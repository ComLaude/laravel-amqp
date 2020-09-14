<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\Tests\BaseTest;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AMQPChannelTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    public function testCreateAmqpChannel()
    {
        $this->assertInstanceOf($this->master, AmqpChannel::class);
    }

    protected function setUp()
    {
        parent::setUp();

        $this->master = AmqpChannel::create( (array) $this->properties, Array( 'queue' => 'testing' ) );
    }
}
