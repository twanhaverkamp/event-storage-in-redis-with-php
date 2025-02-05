<?php

declare(strict_types=1);

namespace TwanHaverkamp\EventStorageInRedisWithPhp\Tests\Unit\Event\EventStore;

use PHPUnit\Framework\Attributes;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface as PredisClientInterface;
use Predis\PredisException;
use TwanHaverkamp\EventSourcingWithPhp\Aggregate;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventSourcingWithPhp\Event\Exception;
use TwanHaverkamp\EventSourcingWithPhp\Example;
use TwanHaverkamp\EventStorageInRedisWithPhp\Event\EventStore;

#[Attributes\CoversClass(EventStore\Redis::class)]
class RedisTest extends TestCase
{
    protected Aggregate\AggregateInterface $aggregate;

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'load\' throws an EventRetrievalFailedException when getting keys fails')]
    public function loadFailedToGetSortedSetThrowsEventRetrievalFailedException(): void
    {
        $this->expectException(Exception\EventRetrievalFailedException::class);
        $this->expectExceptionMessage(sprintf(
            'Failed to fetch keys for Aggregate with AggregateRootId %s.',
            $aggregateRootId = $this->aggregate->getAggregateRootId()->toString(),
        ));

        $client = $this->createMock(PredisClientInterface::class);
        $client
            ->method('__call')
            ->willReturnCallback(function ($method) {
                if ($method === 'zrange') {
                    throw new class extends PredisException {
                    };
                }

                return null;
            });

        $eventStore = new EventStore\Redis(
            $client,
            new EventDescriber\KebabCase(),
        );

        $eventStore->load(
            Example\Aggregate\Invoice::init($aggregateRootId),
        );
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'load\' throws an EventRetrievalFailedException when getting events fails')]
    public function loadFailedToGetKeyValueThrowsEventRetrievalFailedException(): void
    {
        $describer = new EventDescriber\KebabCase();

        $key = sprintf(
            '%s:%d-%s',
            $aggregateRootId = $this->aggregate->getAggregateRootId()->toString(),
            $this->aggregate->getEvents()[0]->getRecordedAt()->format('Uu'),
            $describer->describe($this->aggregate->getEvents()[0]),
        );

        $this->expectException(Exception\EventRetrievalFailedException::class);
        $this->expectExceptionMessage("Failed to fetch Event with key \"$key\".");

        $client = $this->createMock(PredisClientInterface::class);
        $client
            ->method('__call')
            ->willReturnCallback(function ($method) use ($key) {
                if ($method === 'get') {
                    throw new class extends PredisException {
                    };
                }

                return [$key];
            });

        $eventStore = new EventStore\Redis($client, $describer);
        $eventStore->load(
            Example\Aggregate\Invoice::init($aggregateRootId),
        );
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' throws an EventStorageFailedException when adding event fails')]
    public function saveFailedToSetKeyValueThrowsEventStorageFailedException(): void
    {
        $this->expectException(Exception\EventStorageFailedException::class);
        $this->expectExceptionMessage(sprintf(
            'Failed to store Event "%s" for Aggregate with AggregateRootId %s.',
            $this->aggregate->getEvents()[0]::class,
            $this->aggregate->getAggregateRootId()->toString(),
        ));

        $client = $this->createMock(PredisClientInterface::class);
        $client
            ->method('__call')
            ->willReturnCallback(function ($method) {
                if ($method === 'set') {
                    throw new class extends PredisException {
                    };
                }

                return null;
            });

        $eventStore = new EventStore\Redis(
            $client,
            new EventDescriber\KebabCase(),
        );

        $eventStore->save($this->aggregate);
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' throws an EventStorageFailedException when adding keys fails')]
    public function saveFailedToAddSortedSetThrowsEventStorageFailedException(): void
    {
        $describer = new EventDescriber\KebabCase();

        $key = sprintf(
            '%s:%d-%s',
            $aggregateRootId = $this->aggregate->getAggregateRootId()->toString(),
            $this->aggregate->getEvents()[0]->getRecordedAt()->format('Uu'),
            $describer->describe($this->aggregate->getEvents()[0]),
        );

        $this->expectException(Exception\EventStorageFailedException::class);
        $this->expectExceptionMessage(sprintf(
            'Failed to store key "%s" for Aggregate with AggregateRootId %s.',
            $key,
            $aggregateRootId,
        ));

        $client = $this->createMock(PredisClientInterface::class);
        $client
            ->method('__call')
            ->willReturnCallback(function ($method) {
                if ($method === 'zadd') {
                    throw new class extends PredisException {
                    };
                }

                return null;
            });

        $eventStore = new EventStore\Redis($client, $describer);
        $eventStore->save($this->aggregate);
    }

    #[Attributes\Test]
    #[Attributes\TestDox('Assert that \'save\' removed all recorded Events from the Aggregate')]
    public function saveRemovedRecordedEventsFromAggregate(): void
    {
        $eventStore = new EventStore\Redis(
            $this->createStub(PredisClientInterface::class),
            new EventDescriber\KebabCase(),
        );

        $eventStore->save($this->aggregate);

        static::assertCount(0, $this->aggregate->getEvents());
    }

    protected function setUp(): void
    {
        $this->aggregate = Example\Aggregate\Invoice::create(
            '12-34',
            new Example\Aggregate\DTO\Item('prod.123.456', 'Product', 3, 5.95, 21.),
            new Example\Aggregate\DTO\Item(null, 'Shipping', 1, 4.95, 0.),
        );

        $paymentTransaction = $this->aggregate->startPaymentTransaction('Manual', 10.);
        $this->aggregate->completePaymentTransaction($paymentTransaction->id);
    }
}
