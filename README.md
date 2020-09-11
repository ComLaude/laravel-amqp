# ComLaude/laravel-amqp
Simple PhpAmqpLib wrapper for interaction with RabbitMQ 

## Installation

### Composer

Add the following to your require part within the composer.json: 

```js
"comlaude/laravel-amqp": "1.*"
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
            'timeout'             => 0
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

### Push message with routing key and custom queue

```php	
    Amqp::publish('routing-key', 'message' , ['queue' => 'queue-name']);
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
\Amqp::publish('', 'message' , [
    'exchange_type' => 'fanout',
    'exchange' => 'amq.fanout',
]);
```

## Credits

* Some concepts were used from https://github.com/bschmitt/laravel-amqp

## License

This package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)