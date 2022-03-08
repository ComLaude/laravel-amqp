<?php

namespace ComLaude\Amqp\Tests;

use Mockery;
use PHPUnit\Framework\TestCase;

class BaseTest extends TestCase
{
    protected $properties;

    public function setUp(): void
    {
        $amqpConfig = include dirname(__FILE__) . '/../config/amqp.php';
        $this->properties = $amqpConfig['properties'][$amqpConfig['use']];
    }

    public function tearDown(): void
    {
        Mockery::close();
    }
}
