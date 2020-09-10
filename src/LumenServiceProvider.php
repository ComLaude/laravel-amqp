<?php

namespace Comlaude\Amqp;

use Illuminate\Support\ServiceProvider;

/**
 * Lumen service provider
 * 
 * @author David krizanic <david.krizanic@comlaude.com>
 */
class LumenServiceProvider extends ServiceProvider
{

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
    }

    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('Amqp', 'Comlaude\Amqp\Amqp');

        if (! class_exists('Amqp')) {
            class_alias('Comlaude\Amqp\Facades\Amqp', 'Amqp');
        }
    }
}
