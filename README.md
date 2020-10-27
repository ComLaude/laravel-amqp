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
            'host'                => 'localhost',
            'port'                => 5672,
            'username'            => 'guest',
            'password'            => 'guest',
            'vhost'               => '/',
            'exchange'            => 'amq.topic',
            'exchange_type'       => 'topic',
            'consumer_tag'        => 'consumer',
            'ssl_options'         => [], // See https://secure.php.net/manual/en/context.ssl.php
            'connect_options'     => [], // See https://github.com/php-amqplib/php-amqplib/blob/master/PhpAmqpLib/Connection/AMQPSSLConnection.php
            'queue_properties'    => ['x-ha-policy' => ['S', 'all']],
            'exchange_properties' => [],
            'timeout'             => 0,
            'bindings' => [ // The default declared queues and bindings for those queues
                [
                    'queue'    => 'example_queue',
                    'routing'  => 'example_routing_key',
                ],
            ]
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

### Disable

```php
Amqp::off();
```

### Enable

```php
Amqp::on();
```

## Credits

* Some concepts were used from https://github.com/bschmitt/laravel-amqp

## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)