<?php

namespace ComLaude\Amqp;

use Closure;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class OpenTelemetryAmqp
{
    private const INSTRUMENTATION_NAME = 'comlaude/laravel-amqp';

    // Held statically so endConsume() can close them from acknowledge()/reject(),
    // preventing trace context leaking between messages in long-lived consumers.
    private static mixed $consumerSpan = null;
    private static mixed $consumerScope = null;

    public static function beginConsume(string $queue, AMQPMessage $message): void
    {
        if (! self::isAvailable()) {
            return;
        }

        self::$consumerSpan = self::tracer()
            ->spanBuilder(sprintf('AMQP process %s', $queue))
            ->setParent(self::extract($message))
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CONSUMER)
            ->setAttributes(self::attributes($message->getExchange() ?? '', $message->getRoutingKey() ?? '', $message, 'process', $queue))
            ->startSpan();

        self::$consumerScope = self::$consumerSpan->activate();
    }

    public static function endConsume(?Throwable $exception = null): void
    {
        if (self::$consumerSpan === null) {
            return;
        }

        if ($exception !== null) {
            self::$consumerSpan->recordException($exception);
            self::$consumerSpan->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        self::$consumerScope?->detach();
        self::$consumerSpan->end();
        self::$consumerScope = null;
        self::$consumerSpan = null;
    }

    public static function publish($exchange, string $route, AMQPMessage $message, Closure $publish): void
    {
        if (! self::isAvailable()) {
            $publish();
            return;
        }

        $span = self::tracer()
            ->spanBuilder(sprintf('AMQP publish %s', $route))
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_PRODUCER)
            ->setAttributes(self::attributes((string) $exchange, $route, $message, 'publish'))
            ->startSpan();

        $scope = $span->activate();

        try {
            self::inject($message);
            $publish();
        } catch (Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $exception->getMessage());
            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private static function isAvailable(): bool
    {
        return class_exists(\OpenTelemetry\API\Globals::class)
            && interface_exists(\OpenTelemetry\API\Trace\SpanKind::class)
            && interface_exists(\OpenTelemetry\API\Trace\StatusCode::class)
            && interface_exists(\OpenTelemetry\Context\Propagation\PropagationGetterInterface::class)
            && interface_exists(\OpenTelemetry\Context\Propagation\PropagationSetterInterface::class);
    }

    private static function tracer()
    {
        return \OpenTelemetry\API\Globals::tracerProvider()->getTracer(self::INSTRUMENTATION_NAME);
    }

    private static function inject(AMQPMessage $message): void
    {
        $headers = self::headers($message);

        \OpenTelemetry\API\Globals::propagator()->inject($headers, new class implements \OpenTelemetry\Context\Propagation\PropagationSetterInterface {
            public function set(&$carrier, string $key, string $value): void
            {
                $carrier[$key] = $value;
            }
        });

        $message->set('application_headers', new AMQPTable($headers));
    }

    private static function extract(AMQPMessage $message)
    {
        return \OpenTelemetry\API\Globals::propagator()->extract(self::headers($message), new class implements \OpenTelemetry\Context\Propagation\PropagationGetterInterface {
            public function keys($carrier): array
            {
                return array_keys($carrier);
            }

            public function get($carrier, string $key): ?string
            {
                return isset($carrier[$key]) ? (string) $carrier[$key] : null;
            }
        });
    }

    private static function headers(AMQPMessage $message): array
    {
        if (! $message->has('application_headers')) {
            return [];
        }

        $headers = $message->get('application_headers');

        if ($headers instanceof AMQPTable) {
            return $headers->getNativeData();
        }

        return is_array($headers) ? $headers : [];
    }

    private static function attributes(string $exchange, string $route, AMQPMessage $message, string $operation, ?string $queue = null): array
    {
        $attributes = [
            'messaging.system' => 'rabbitmq',
            'messaging.operation' => $operation,
            'messaging.destination.name' => $exchange,
            'messaging.rabbitmq.routing_key' => $route,
            'messaging.message.body.size' => strlen($message->getBody()),
        ];

        if ($queue !== null) {
            $attributes['messaging.destination.name'] = $queue;
            $attributes['messaging.source.name'] = $queue;
        }

        if ($message->has('correlation_id')) {
            $attributes['messaging.message.conversation_id'] = $message->get('correlation_id');
        }

        if ($message->has('message_id')) {
            $attributes['messaging.message.id'] = $message->get('message_id');
        }

        return $attributes;
    }
}
