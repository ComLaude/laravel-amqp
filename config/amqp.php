<?php

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
            'queue_durable'         => true,
            'queue_exclusive'       => false,
            'queue_auto_delete'     => false,         // only when not using quorum queues
            'queue_nowait'          => false,
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'quorum'],
            ],

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
