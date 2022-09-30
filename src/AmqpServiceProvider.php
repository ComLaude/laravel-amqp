<?php

namespace ComLaude\Amqp;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider
 *
 * @author David krizanic <david.krizanic@comlaude.com>
 */
class AmqpServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = true;

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->bind('Amqp', 'ComLaude\Amqp\Amqp');
        if (! class_exists('Amqp')) {
            class_alias('ComLaude\Amqp\Facades\Amqp', 'Amqp');
        }

        $this->publishes([
            __DIR__ . '/../config/amqp.php' => config_path('amqp.php'),
        ]);
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['Amqp'];
    }
}
