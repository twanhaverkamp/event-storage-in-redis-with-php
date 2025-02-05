<?php

declare(strict_types=1);

namespace TwanHaverkamp\EventStorageInRedisWithPhp\Event\EventStore;

use DateTime;
use DateTimeImmutable;
use Generator;
use JsonException;
use Predis\ClientInterface as PredisClientInterface;
use Predis\PredisException;
use TwanHaverkamp\EventSourcingWithPhp\Aggregate;
use TwanHaverkamp\EventSourcingWithPhp\Event;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventStore;
use TwanHaverkamp\EventSourcingWithPhp\Event\Exception;

class Redis implements EventStore\EventStoreInterface
{
    protected const int KEY_RANGE_LIMIT = 25;

    public function __construct(
        protected PredisClientInterface $client,
        protected EventDescriber\EventDescriberInterface $describer,
    ) {
    }

    /**
     * @throws Exception\EventRetrievalFailedException when fetching keys with ZRANGE fails or
     *                                                 when fetching events with GET fails.
     */
    public function load(Aggregate\AggregateInterface $aggregate): void
    {
        foreach ($this->getKeys($aggregate) as $key) {
            try {
                /** @var string $json */
                $json = $this->client->get($key);
            } catch (PredisException $e) {
                throw new Exception\EventRetrievalFailedException(
                    message: "Failed to fetch Event with key \"$key\".",
                    previous: $e,
                );
            }

            /**
             * @var array{
             *     eventClass: class-string<Event\EventInterface>,
             *     payload: array<string, mixed>,
             *     recordedAt: string,
             *     microseconds: int,
             * } $data
             */
            $data = json_decode($json, true);

            /** @var DateTime $recordedAt */
            $recordedAt = DateTime::createFromFormat(DATE_ATOM, $data['recordedAt']);
            $recordedAt->setTime(
                (int)$recordedAt->format('H'),
                (int)$recordedAt->format('i'),
                (int)$recordedAt->format('s'),
                $data['microseconds'],
            );

            $aggregate->apply($data['eventClass']::fromPayload(
                $aggregate->getAggregateRootId(),
                $data['payload'],
                DateTimeImmutable::createFromMutable($recordedAt),
            ));
        }
    }

    /**
     * @throws Exception\EventStorageFailedException when JSON encoding of a payload throws a {@see JsonException}
     *                                               or the Redis client throws a {@see PredisException}.
     */
    public function save(Aggregate\AggregateInterface $aggregate): void
    {
        foreach ($aggregate->getEvents() as $event) {
            try {
                $this->client->set($key = $this->createKey($event), json_encode([
                    'eventClass'   => $event::class,
                    'payload'      => $event->getPayload(),
                    'recordedAt'   => $event->getRecordedAt()->format(DATE_ATOM),
                    'microseconds' => (int)$event->getRecordedAt()->format('u'),
                ], JSON_THROW_ON_ERROR));
            } catch (JsonException | PredisException $e) {
                throw new Exception\EventStorageFailedException(
                    message: sprintf(
                        'Failed to store Event "%s" for Aggregate with AggregateRootId %s.',
                        $event::class,
                        $event->getAggregateRootId()->toString(),
                    ),
                    previous: $e,
                );
            }

            try {
                $this->client->zadd(
                    $aggregate->getAggregateRootId()->toString(),
                    [$key => (int)$event->getRecordedAt()->format('Uu')],
                );
            } catch (PredisException $e) {
                throw new Exception\EventStorageFailedException(
                    message: sprintf(
                        'Failed to store key "%s" for Aggregate with AggregateRootId %s.',
                        $key,
                        $aggregate->getAggregateRootId()->toString(),
                    ),
                    previous: $e,
                );
            }

            $aggregate->remove($event);
        }
    }

    protected function createKey(Event\EventInterface $event): string
    {
        return sprintf(
            '%s:%d-%s',
            $event->getAggregateRootId()->toString(),
            $event->getRecordedAt()->format('Uu'),
            $this->describer->describe($event),
        );
    }

    /**
     * @return Generator<string>
     * @throws Exception\EventStorageFailedException when fetching keys with ZRANGE fails.
     */
    protected function getKeys(Aggregate\AggregateInterface $aggregate): Generator
    {
        do {
            try {
                $keys = $this->client->zrange(
                    $aggregate->getAggregateRootId()->toString(),
                    $start ??= 0,
                    $stop ??= (static::KEY_RANGE_LIMIT - 1),
                );
            } catch (PredisException $e) {
                throw new Exception\EventRetrievalFailedException(
                    message: sprintf(
                        'Failed to fetch keys for Aggregate with AggregateRootId %s.',
                        $aggregate->getAggregateRootId()->toString(),
                    ),
                    previous: $e,
                );
            }

            foreach ($keys as $key) {
                yield $key;
            }

            $start += static::KEY_RANGE_LIMIT;
            $stop  += static::KEY_RANGE_LIMIT;
        } while (count($keys) === static::KEY_RANGE_LIMIT);
    }
}
