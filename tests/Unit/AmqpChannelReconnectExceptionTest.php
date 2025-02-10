<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\Exceptions\AmqpChannelSilentlyRestartedException;
use ComLaude\Amqp\Tests\BaseTest;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel as AMQPChannelBase;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use ReflectionClass;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelReconnectExceptionTest extends BaseTest
{
    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testReconnect()
    {
        $mockedChannel = Mockery::mock(AmqpChannel::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $mockedUnderlyingChannel = Mockery::mock(AMQPChannelBase::class);
        $mockedUnderlyingChannel->shouldReceive('is_consuming')
            ->once()
            ->andThrow(new AMQPProtocolChannelException(1, 1, 1));

        $reflection = new ReflectionClass(AmqpChannel::class);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        $property->setValue($mockedChannel, $mockedUnderlyingChannel);

        $mockedChannel->shouldReceive('preConnectionEstablished')
            ->once();
        $mockedChannel->shouldReceive('connect')
            ->once();
        $mockedChannel->shouldReceive('declareExchange')
            ->once();
        $mockedChannel->shouldReceive('postConnectionEstablished')
            ->once();
        $this->expectException(AmqpChannelSilentlyRestartedException::class);
        $mockedChannel->reconnect();
    }

    public function testReconnectWithOpenConsumer()
    {
        $mockedChannel = Mockery::mock(AmqpChannel::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $mockedUnderlyingChannel = Mockery::mock(AMQPChannelBase::class);
        $mockedUnderlyingChannel->shouldReceive('is_consuming')
            ->once()
            ->andReturn(true);
        $mockedUnderlyingChannel->shouldReceive('close')
            ->once()
            ->andReturn(true);
        $mockedChannel->shouldReceive('disconnect')
            ->andThrow(new AMQPProtocolChannelException(1, 1, 1));

        $reflection = new ReflectionClass(AmqpChannel::class);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        $property->setValue($mockedChannel, $mockedUnderlyingChannel);

        $mockedChannel->shouldReceive('preConnectionEstablished')
            ->once();
        $mockedChannel->shouldReceive('connect')
            ->once();
        $mockedChannel->shouldReceive('declareExchange')
            ->once();
        $mockedChannel->shouldReceive('postConnectionEstablished')
            ->once();
        $this->expectException(AmqpChannelSilentlyRestartedException::class);
        $mockedChannel->reconnect();
    }
    public function testReconnectWithOpenConsumerAndSuccessfulDisconnect()
    {
        $mockedChannel = Mockery::mock(AmqpChannel::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $mockedUnderlyingChannel = Mockery::mock(AMQPChannelBase::class);
        $mockedUnderlyingChannel->shouldReceive('is_consuming')
            ->once()
            ->andReturn(true);
        $mockedUnderlyingChannel->shouldReceive('close')
            ->once()
            ->andReturn(true);
        $mockedChannel->shouldReceive('disconnect')
            ->andReturn(true);

        $reflection = new ReflectionClass(AmqpChannel::class);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        $property->setValue($mockedChannel, $mockedUnderlyingChannel);

        $mockedChannel->shouldReceive('preConnectionEstablished')
            ->once();
        $mockedChannel->shouldReceive('connect')
            ->once();
        $mockedChannel->shouldReceive('declareExchange')
            ->once();
        $mockedChannel->shouldReceive('postConnectionEstablished')
            ->once();
        $this->expectException(AmqpChannelSilentlyRestartedException::class);
        $mockedChannel->reconnect();
    }

    public function testReconnectIntentional()
    {
        $mockedChannel = Mockery::mock(AmqpChannel::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $mockedUnderlyingChannel = Mockery::mock(AMQPChannelBase::class);
        $mockedUnderlyingChannel->shouldReceive('is_consuming')
            ->once()
            ->andThrow(new AMQPProtocolChannelException(1, 1, 1));

        $reflection = new ReflectionClass(AmqpChannel::class);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        $property->setValue($mockedChannel, $mockedUnderlyingChannel);

        $mockedChannel->shouldReceive('preConnectionEstablished')
            ->once();
        $mockedChannel->shouldReceive('connect')
            ->once();
        $mockedChannel->shouldReceive('declareExchange')
            ->once();
        $mockedChannel->shouldReceive('postConnectionEstablished')
            ->once();
        $mockedChannel->reconnect(true);
        $this->assertTrue(true);
    }
}
