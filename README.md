# Event storage in Redis with PHP

This package is a [Redis](https://redis.io/) implementation for the
["Event Sourcing with PHP"](https://github.com/twanhaverkamp/event-sourcing-with-php) library.

**Table of Contents**

- [Usage](#usage)
  - [Installation](#installation)
  - [Implementation](#implementation)
    - [Connect with Redis](#connect-with-redis)
    - [Event storage](#event-storage)
  - [Dependency injection](#dependency-injection)
    - [Laravel project](#laravel-project)
    - [Symfony project](#symfony-project)

## Usage

### Installation

**Requirements:**
- PHP 8.3 (or higher)

If you're using [Composer](https://getcomposer.org/) in your project you can run the following command:

```shell
composer require composer require twanhaverkamp/event-storage-in-redis-with-php:^1.0 
```

### Implementation
Most PHP frameworks like Symfony and Laravel allows you to register classes as services; If you like, you can register
this Event store where you bind it to the EventStoreInterface 

#### Connect with Redis
When constructing the [Event Store](/src/Event/EventStore/Redis.php) you're required to pass an instance of
the [Predis client](https://github.com/predis/predis) as first argument and an Event describer instance as second
argument.

```php

// ...

use Predis\Client as PredisClient;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber;
use TwanHaverkamp\EventStorageInRedisWithPhp\Event\EventStore;

// ...

$eventStore = new EventStore\Redis(
    new PredisClient([
        'scheme' => 'tcp',
        'host'   => 'http://redis-stack',
        'port'   => 6379,
    ]),
    new EventDescriber\KebabCase(),
);

// ...
```

> An Event describer can be found in the "Event Sourcing with PHP" library, which is automatically installed
> whenever you install this package. You can create your own describer if you like; just make sure it implements the
> EventDescriberInterface which can also be found in the "Event Sourcing with PHP" library.

#### Event storage
When you pass an Aggregate to the `save` function it loops over its Events and for every Event it will create
a new [String](https://redis.io/docs/latest/develop/data-types/strings/) where the key is constructed with
the AggregateRootId, the recordedAt value as timestamp (including microseconds) and Event name.

Every Aggregate get its own [Sorted Set](https://redis.io/docs/latest/develop/data-types/sorted-sets/), where the
Event keys are stored. _With this solution we don't need to perform slow and complex queries._

### Dependency injection

#### Laravel project

Create your own service provider when your working in a [Laravel](https://laravel.com) project to bind the Redis class
to the EventStoreInterface:

```php
namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Predis\Client as PredisClient;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber\KebabCase;
use TwanHaverkamp\EventSourcingWithPhp\Event\EventStore\EventStoreInterface;
use TwanHaverkamp\EventStorageInRedisWithPhp\Event\EventStore\Redis;

class EventStoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventStoreInterface::class, function (Application $app) {
            return new Redis(
                new PredisClient([
                    'scheme' => 'tcp',
                    'host'   => config('redis.host'),
                    'port'   => config('redis.port'),
                ]),
                new KebabCase(),
            );
        });
    }
}
```

#### Symfony project

If you're working in a [Symfony](https://symfony.com/) project, you can leverage it's built-in "autowire" mechanism
by registering the Event Store as a service in the `services.yaml`: 

```yaml
services:
  _defaults:
    bind:
      Predis\ClientInterface: '@redis.client'
      TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber\EventDescriberInterface: '@event.describer'
      TwanHaverkamp\EventSourcingWithPhp\Event\EventStore\EventStoreInterface: '@event_store.redis'

  # ...

  event.describer:
    class: TwanHaverkamp\EventSourcingWithPhp\Event\EventDescriber\KebabCase

  event_store.redis:
    class: TwanHaverkamp\EventStorageInRedisWithPhp\Event\EventStore\Redis

  redis.client:
    class: Predis\Client
    arguments:
      - { scheme: 'tcp', host: '%env(string:REDIS_HOST)%', port: '%env(int:REDIS_PORT)%' }

  # ...

```
