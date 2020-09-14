<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\Tests\BaseTest;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AMQPChannelTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    protected function setUp()
    {
        parent::setUp();

        $this->master = \ComLaude\Amqp\AmqpChannel::create( (array) $this->properties, Array( 'queue' => 'testing' ) );
    }

    public function testCreateAmqpChannel()
    {
        $this->assertInstanceOf($this->master, \ComLaude\Amqp\AmqpChannel);
    }

}
