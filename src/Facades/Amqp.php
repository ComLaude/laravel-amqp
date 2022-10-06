<?php

namespace ComLaude\Amqp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 * @see ComLaude\Amqp\Amqp
 */
class Amqp extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'Amqp';
    }
}
