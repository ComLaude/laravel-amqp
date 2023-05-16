<?php

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Define which configuration should be used
    |--------------------------------------------------------------------------
    */

    'use' => 'production',

    /*
    |--------------------------------------------------------------------------
    | AMQP properties separated by key
    |--------------------------------------------------------------------------
    */

    'properties' => [

        'production' => [
            'host'                  => 'localhost',
            'port'                  =>  5672,
            'username'              => '',
            'password'              => '',
            'vhost'                 => '/',
            'connect_options'       => [],
            'ssl_options'           => [],

            'register_pcntl_heartbeat_sender' => false, // Signal based heartbeat sender https://github.com/php-amqplib/php-amqplib/pull/815

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
                // 'x-dead-letter-exchange' => ['S', 'amq.topic-dlx'], // if provided an exchange and queue will be automatically declared
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
