<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
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

    public function testCreateAmqpChannelWithoutTls()
    {
        $properties = array_merge($this->properties, [
            'queue' => 'test1',
            'use_tls' => 0,
            'port' =>  5672,
            'connect_context' => stream_context_create([
                'ssl' => [
                    'verify_peer'       => true,
                    'verify_peer_name'  => true,
                    'allow_self_signed' => false,
                ],
            ]),
        ]);

        AmqpFactory::create($properties);
        $this->assertTrue(true);
    }

    public function testCreateAmqpChannelWithTlsOnNonTlsPort()
    {
        $properties = array_merge($this->properties, [
            'queue' => 'test2',
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
        $this->expectExceptionMessageMatches('/Unable to connect to ssl:\/\/localhost:5672/');
        AmqpFactory::create($properties);
    }
}
