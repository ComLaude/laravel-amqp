<?php

namespace Comlaude\Amqp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @author David krizanic <david.krizanic@comlaude.com>
 * @see Comlaude\Amqp\Amqp
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
