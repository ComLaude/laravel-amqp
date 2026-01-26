<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelSslTest extends BaseTest
{
    protected $master;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'queue' => 'test',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'test',
                    'routing'  => 'example.route.key',
                ],
            ],
            'timeout' => 1,
        ]);
    }

    public function testCreateAmqpChannelWithoutTlsOnTlsPort()
    {
        $properties = array_merge($this->properties, [
            'use_tls' => 0,
            'port' =>  5671,
        ]);

        $this->expectException(AMQPConnectionClosedException::class);
        $this->expectExceptionMessage('Broken pipe or closed connection');
        AmqpFactory::create($properties);
    }

    public function testCreateAmqpChannelWithTlsOnNonTlsPort()
    {
        $properties = array_merge($this->properties, [
            'use_tls' => 1,
            'port' =>  5672,
            'connect_context' => stream_context_create([
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ]),
        ]);

        $this->expectException(AMQPIOException::class);
        $this->expectExceptionMessage('Unable to connect');
        AmqpFactory::create($properties);
    }
}
