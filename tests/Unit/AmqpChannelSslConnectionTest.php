<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Exception\AMQPIOException;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelSslConnectionTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'host'          => 'localhost',
            'port'          =>  5672,
            'queue' => 'test_amqp_connection',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'test',
                    'routing'  => 'example.route.key',
                ],
            ],
            'timeout' => 1,
            'ssl_options' => [
                'connect' => true,
            ],
        ]);
    }

    public function testConnectWithSslOptions()
    {
        // We cannot safely simulate SSL connections inside travis, so we'll just make sure it attempts to use the correct protocol for the connection
        $this->expectException(AMQPIOException::class);
        $this->expectExceptionMessage('stream_socket_client()');
        $this->expectExceptionMessage('able to connect to ssl://localhost:5672');
        $this->master = AmqpFactory::create($this->properties);
    }
}
