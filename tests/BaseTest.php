<?php

namespace ComLaude\Amqp\Tests;

use Mockery;
use PHPUnit_Framework_TestCase;

class BaseTest extends PHPUnit_Framework_TestCase
{
    protected $properties;

    protected function setUp()
    {
        $amqpConfig = include dirname(__FILE__) . '/../config/amqp.php';
        $this->properties = $amqpConfig['properties'][$amqpConfig['use']];
    }

    protected function tearDown()
    {
        Mockery::close();
    }
}
