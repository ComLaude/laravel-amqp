<?php

namespace ComLaude\Amqp\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @author David krizanic <david.krizanic@comlaude.com>
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
