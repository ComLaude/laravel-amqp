# ComLaude/laravel-amqp
Simple PhpAmqpLib wrapper for interaction with RabbitMQ 

[![Build Status](https://travis-ci.com/ComLaude/laravel-amqp.svg?branch=master)](https://travis-ci.com/ComLaude/laravel-amqp)
[![Latest Stable Version](https://poser.pugx.org/comlaude/laravel-amqp/v)](//packagist.org/packages/comlaude/laravel-amqp)
[![License](https://poser.pugx.org/comlaude/laravel-amqp/license)](//packagist.org/packages/comlaude/laravel-amqp)

## Installation

### Composer

Add the following to your require part within the composer.json: 

```js
"comlaude/laravel-amqp": "^1.0.0"
```
```batch
$ php composer update
```

or

```
$ php composer require comlaude/laravel-amqp
```

## Integration

### Lumen

Create a **config** folder in the root directory of your Lumen application and copy the content
from **vendor/comlaude/laravel-amqp/config/amqp.php** to **config/amqp.php**.

Adjust the properties to your needs.

```php
return [

    'use' => 'production',

    'properties' => [

        'production' => [
            'host'                  => 'localhost',
            'port'                  =>  5672,
            'username'              => '',
            'password'              => '',
            'vhost'                 => '/',
            'connect_options'       => [],
            'ssl_options'           => [],

            'exchange'              => 'amq.topic',
            'exchange_type'         => 'topic',
            'exchange_passive'      => false,
            'exchange_durable'      => true,
            'exchange_auto_delete'  => false,
            'exchange_internal'     => false,
            'exchange_nowait'       => false,
            'exchange_properties'   => [],

            'queue_force_declare'   => false,
            'queue_passive'         => false,
            'queue_durable'         => true,          // only change when not using quorum queues
            'queue_exclusive'       => false,
            'queue_auto_delete'     => false,         // only change when not using quorum queues
            'queue_nowait'          => false,
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'quorum'],
                // 'x-dead-letter-exchange' => ['S', 'amq.topic-dlx'], // if provided an exchange and queue (queue_name-dlx) will be automatically declared
                // 'x-delivery-limit' => ['I', 5],                     // the delivery limit will be set on the relevant queue but not the DLX queue itself
            ],
            'queue_acknowledge_is_final' => true,     // if important work is done inside a consumer after the acknowledge call, this should be false
            'queue_reject_is_final'      => true,     // if important work is done inside a consumer after the reject call, this should be false

            'consumer_tag'              => '',
            'consumer_no_local'         => false,
            'consumer_no_ack'           => false,
            'consumer_exclusive'        => false,
            'consumer_nowait'           => false,
            'timeout'                   => 0,        // seconds
            'persistent'                => false,
            'persistent_restart_period' => 0,        // seconds
            'request_accepted_timeout'  => 0.5,      // seconds in decimal accepted
            'request_handled_timeout'   => 5,        // seconds in decimal accepted
            'request_must_be_handled'   => false,    // if true, the request must be handled by the consumer even if the requestor is not listening

            'qos'                   => true,
            'qos_prefetch_size'     => 0,
            'qos_prefetch_count'    => 1,
            'qos_a_global'          => false,

            /*
            |--------------------------------------------------------------------------
            | An example binding set up when declaring exchange and queues
            |--------------------------------------------------------------------------
            |'bindings' => [
            |    [
            |        'queue'    => 'example',
            |        'routing'  => 'example.route.key',
            |    ],
            |],
            */
        ],

    ],

];
```

Register the Lumen Service Provider in **bootstrap/app.php**:

```php
/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
*/

//...

$app->configure('amqp');
$app->register(ComLaude\Amqp\LumenServiceProvider::class);

//...
```

Add Facade Support for Lumen 5.2+

```php
//...
$app->withFacades(true, [
    'ComLaude\Amqp\Facades\Amqp' => 'Amqp',
]);
//...
```


### Laravel

Open **config/app.php** and add the service provider and alias:

```php
'ComLaude\Amqp\AmqpServiceProvider',
```

```php
'Amqp' => 'ComLaude\Amqp\Facades\Amqp',
```


## Publishing a message

### Push message with routing key

```php
Amqp::publish('routing-key', 'message');
```

### Push message with routing key and overwrite properties

```php	
Amqp::publish('routing-key', 'message' , ['exchange' => 'amq.direct']);
```


## Consuming messages

### Consume messages forever

```php
Amqp::consume(function ($message) {

    var_dump($message->body);

    Amqp::acknowledge($message);

});
```

### Consume messages, with custom settings

```php
Amqp::consume(function ($message) {

   var_dump($message->body);

   Amqp::acknowledge($message);

}, [
    'timeout' => 2,
    'vhost'   => 'vhost3',
    'queue'   => 'queue-name',
    'persistent' => true // required if you want to listen forever
]);
```

## Fanout example

### Publishing a message

```php
Amqp::publish('', 'message' , [
    'exchange_type' => 'fanout',
    'exchange' => 'amq.fanout',
]);
```

## Disable publishing

This is useful for development and sync requirements, if you are using observers or events to trigger messages over AMQP you may want to temporarily disable the publishing of messages. When turning the publishing off the publish method will silently drop the message and return.

### Check state

```php
if(Amqp::isEnabled()) {
    // It is going to publish
}
```
### Disable

```php
Amqp::disable();
```

### Enable

```php
Amqp::enable();
```

## Remote procedure call server and client

RPC is potentially an anti-pattern in a microservices world so do not use it carelessly, nevertheless sometimes you just need that request-response behaviour and you're willing to accept its limitations. Simply return a response from within a consumer handler, if the message is a request from a client, the response will automatically be routed to the correct requestor. There are 2 configurable timeouts to prevent infinite-blocking waits.

```request_accepted_timeout``` - time to wait for confirmation from server that a job is being worked on, this is a check if anybody is listening at all and should be quite small

```request_handled_timeout``` - time to wait for the full request to be completed (all messages), be careful to ensure this is large enough if your job is long-lasting or if the number of messages to be handled is large

### Server

```php
Amqp::consume(function ($message) {

   Amqp::acknowledge($message);

   return "I handled this message " . $message->getBody();

});
```

### Client

```php
Amqp::request('example.routing.key', [

    'message1',
    'message2',

], function ($message) {

   echo("The remote server said " . $message->getBody());

});

// Or for single message requests you can just do
$response = Amqp::requestWithResponse('example.routing.key', 'quickly');
// $response is already the message content as a string "I handled this message quickly"
```

### Consume messages, with dead letter exchange configured

When using the `x-dead-letter-exchange` parameter in queue properties the package will additionally:
- declare the <queue_name>-dlx queue
- declare the exchange itself

When the consumer fails or requeues the message for 5 times the message will instead be routed to this new queue via the dead letter exchange.

```php
Amqp::consume(function ($message) {

   var_dump($message->body);

   Amqp::acknowledge($message);

}, [
    'timeout' => 2,
    'vhost'   => 'vhost3',
    'queue'   => 'my-example-queue',
    'persistent' => true // required if you want to listen forever
    'queue_properties'      => [
        'x-ha-policy' => ['S', 'all'],
        'x-queue-type' => ['S', 'quorum'],
        'x-dead-letter-exchange' => ['S', 'amq.topic-dlx'], // will auto-declare queue named my-example-queue-dlx
        'x-delivery-limit' => ['I', 5], // after 5 deliveries the message is routed to my-example-queue-dlx
    ],
]);
```

## Credits

* Some concepts were used from https://github.com/bschmitt/laravel-amqp

## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)