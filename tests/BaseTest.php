<?php

namespace ComLaude\Amqp\Tests;

use Mockery;
use PHPUnit\Framework\TestCase;

class BaseTest extends TestCase
{
    protected $properties;

    function setUp(): void
    {
        $amqpConfig = include dirname(__FILE__) . '/../config/amqp.php';
        $this->properties = $amqpConfig['properties'][$amqpConfig['use']];
    }

    function tearDown(): void
    {
        Mockery::close();
    }
}
