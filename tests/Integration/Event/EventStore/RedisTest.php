<?php

declare(strict_types=1);

namespace TwanHaverkamp\EventStorageInRedisWithPhp\Tests\Integration\Event\EventStore;

use DateTime;
use DateTimeInterface;
use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventSourcingWithPhp\Event;
use TwanHaverkamp\EventSourcingWithPhp\Example;
use TwanHaverkamp\EventStorageInRedisWithPhp\Event\EventStore;

#[Attributes\CoversClass(EventStore\Redis::class)]
class RedisTest extends TestCase
{
    protected static string|null $aggregateRootId = null;

    /**
     * @var Event\EventInterface[]|null
     */
    protected static array|null $events;

    /**
     * @var string[]|null
     */
    protected static array|null $members = null;

    protected static PredisClient $client;

    public static function setUpBeforeClass(): void
    {
        static::$client = new PredisClient([
            'scheme' => 'tcp',
            'host'   => 'redis-stack',
            'port'   => 6379,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (static::$members !== null) {
            $removed = static::$client->del(static::$members);

            static::assertCount($removed, static::$members);
        }

        if (static::$aggregateRootId !== null && static::$members !== null) {
            $removed = static::$client->zrem(static::$aggregateRootId, ...static::$members);

            static::assertCount($removed, static::$members);
        }
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' doesn\'t fail')]
    public function save(): Example\Aggregate\Invoice
    {
        $this->expectNotToPerformAssertions();

        $invoice = Example\Aggregate\Invoice::create(
            '12-34',
            new Example\Aggregate\DTO\Item('prod.123.456', 'Product', 3, 5.95, 21.),
            new Example\Aggregate\DTO\Item(null, 'Shipping', 1, 4.95, 0.),
        );

        $paymentTransaction = $invoice->startPaymentTransaction('Manual', 10.);
        $invoice->completePaymentTransaction($paymentTransaction->id);

        static::$events = $invoice->getEvents();

        $eventStore = new EventStore\Redis(
            static::$client,
            new EventDescriber\KebabCase(),
        );

        $eventStore->save($invoice);

        return $invoice;
    }

    /**
     * @return string[]
     */
    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' added a \'sorted set\' for each Event, "scored" by \'recordedAt\'')]
    #[Attributes\Depends('save')]
    public function saveAddedEventKeysAsStoredSet(Example\Aggregate\Invoice $invoice): array
    {
        static::$members = $members = static::$client->zrange(
            static::$aggregateRootId = $invoice->getAggregateRootId()->toString(),
            0,
            -1,
        );

        static::assertCount(3, $members);
        static::assertStringContainsString('invoice-was-created', $members[0]);
        static::assertStringContainsString('payment-transaction-was-started', $members[1]);
        static::assertStringContainsString('payment-transaction-was-completed', $members[2]);

        return $members;
    }

    /**
     * @param string[] $members
     */
    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' added Events as \'key/value\', with the expected JSON')]
    #[Attributes\Depends('saveAddedEventKeysAsStoredSet')]
    public function saveAddedEventsAsKeyValue(array $members): void
    {
        $this
            ->assertInvoiceWasCreated($members[0])
            ->assertPaymentTransactionWasStarted($members[1])
            ->assertPaymentTransactionWasCompleted($members[2]);
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'load\' populates the Aggregate')]
    #[Attributes\Depends('save')]
    public function loadPopulatesAggregateWithoutEvents(Example\Aggregate\Invoice $invoice): void
    {
        $aggregateRootId = $invoice->getAggregateRootId()->toString();
        $createdAt = $invoice->createdAt->format(DATE_ATOM);

        $eventStore = new EventStore\Redis(
            static::$client,
            new EventDescriber\KebabCase(),
        );

        $eventStore->load(
            $invoice = Example\Aggregate\Invoice::init($aggregateRootId),
        );

        static::assertCount(0, $invoice->getEvents());

        static::assertSame('12-34', $invoice->number);
        static::assertSame($createdAt, $invoice->createdAt->format(DATE_ATOM));

        static::assertSame('prod.123.456', $invoice->items[0]->reference);
        static::assertSame('Product', $invoice->items[0]->description);
        static::assertSame(3, $invoice->items[0]->quantity);
        static::assertSame(5.95, $invoice->items[0]->price);
        static::assertSame(21., $invoice->items[0]->tax);

        static::assertSame('Shipping', $invoice->items[1]->description);
        static::assertSame(1, $invoice->items[1]->quantity);
        static::assertSame(4.95, $invoice->items[1]->price);
        static::assertSame(0., $invoice->items[1]->tax);

        static::assertSame(22.8, $invoice->getSubTotal());
        static::assertSame(3.75, $invoice->getTax());
        static::assertSame(16.55, $invoice->getTotal());
    }

    protected function assertInvoiceWasCreated(string $key): self
    {
        /**
         * @var array{
         *     eventClass: class-string<Event\EventInterface>,
         *     payload: array{
         *         number: string,
         *         items: array{
         *             array{
         *                 reference: string,
         *                 description: string,
         *                 quantity: int,
         *                 price: float,
         *                 tax: float,
         *             },
         *         },
         *     },
         *     recordedAt: string,
         *     microseconds: int,
         * } $data
         */
        $data = json_decode(static::$client->get($key) ?? '{}', true, JSON_THROW_ON_ERROR);

        static::assertSame(Example\Event\InvoiceWasCreated::class, $data['eventClass']);

        if (isset(static::$events[0]) === false) {
            static::fail(sprintf(
                'Failed to assert that Event \'%s\' exists in memory.',
                Example\Event\InvoiceWasCreated::class,
            ));
        }

        static::assertRecordedAt(
            static::$events[0]->getRecordedAt(),
            $data['recordedAt'],
            $data['microseconds'],
        );

        static::assertSame('12-34', $data['payload']['number']);

        static::assertSame('prod.123.456', $data['payload']['items'][0]['reference']);
        static::assertSame('Product', $data['payload']['items'][0]['description']);
        static::assertSame(3, $data['payload']['items'][0]['quantity']);
        static::assertSame(5.95, $data['payload']['items'][0]['price']);
        static::assertSame(21, $data['payload']['items'][0]['tax']);

        static::assertSame('Shipping', $data['payload']['items'][1]['description']);
        static::assertSame(1, $data['payload']['items'][1]['quantity']);
        static::assertSame(4.95, $data['payload']['items'][1]['price']);
        static::assertSame(0, $data['payload']['items'][1]['tax']);

        return $this;
    }

    protected function assertPaymentTransactionWasStarted(string $key): self
    {
        /**
         * @var array{
         *     eventClass: class-string<Event\EventInterface>,
         *     payload: array{
         *         paymentMethod: string,
         *         amount: float,
         *     },
         *     recordedAt: string,
         *     microseconds: int,
         * } $data
         */
        $data = json_decode(static::$client->get($key) ?? '{}', true, JSON_THROW_ON_ERROR);

        static::assertSame(Example\Event\PaymentTransactionWasStarted::class, $data['eventClass']);

        if (isset(static::$events[1]) === false) {
            static::fail(sprintf(
                'Failed to assert that Event \'%s\' exists in memory.',
                Example\Event\PaymentTransactionWasStarted::class,
            ));
        }

        static::assertRecordedAt(
            static::$events[1]->getRecordedAt(),
            $data['recordedAt'],
            $data['microseconds'],
        );

        static::assertSame('Manual', $data['payload']['paymentMethod']);
        static::assertSame(10, $data['payload']['amount']);

        return $this;
    }

    protected function assertPaymentTransactionWasCompleted(string $key): self
    {
        /**
         * @var array{
         *     eventClass: class-string<Event\EventInterface>,
         *     recordedAt: string,
         *     microseconds: int,
         * } $data
         */
        $data = json_decode(static::$client->get($key) ?? '{}', true, JSON_THROW_ON_ERROR);

        static::assertSame(Example\Event\PaymentTransactionWasCompleted::class, $data['eventClass']);

        if (isset(static::$events[2]) === false) {
            static::fail(sprintf(
                'Failed to assert that Event \'%s\' exists in memory.',
                Example\Event\PaymentTransactionWasCompleted::class,
            ));
        }

        static::assertRecordedAt(
            static::$events[2]->getRecordedAt(),
            $data['recordedAt'],
            $data['microseconds'],
        );

        return $this;
    }

    protected static function assertRecordedAt(
        DateTimeInterface $expectedAt,
        string $datetime,
        int $microseconds,
    ): void {
        $recordedAt = new DateTime($datetime);
        $recordedAt->setTime(
            (int)$recordedAt->format('H'),
            (int)$recordedAt->format('i'),
            (int)$recordedAt->format('s'),
            $microseconds,
        );

        static::assertSame(
            $expectedAt->format('Uu'),
            $recordedAt->format('Uu'),
        );
    }
}
