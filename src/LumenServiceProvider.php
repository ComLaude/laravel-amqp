<?php

namespace ComLaude\Amqp;

use Illuminate\Support\ServiceProvider;

/**
 * Lumen service provider
 *
 * @author David Krizanic <david.krizanic@comlaude.com>
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
        $this->app->bind('Amqp', 'ComLaude\Amqp\Amqp');

        if (! class_exists('Amqp')) {
            class_alias('ComLaude\Amqp\Facades\Amqp', 'Amqp');
        }
    }
}
