<?php
namespace ComLaude\Amqp;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Handles OpenTelemetry trace context propagation for AMQP messages
 *
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpTraceContextPropagator
{
    public static $scope = null;

    /**
     * Inject the current trace context into an AMQP message's headers
     * Also adds AMQP metadata (routing key, exchange) as span attributes
     *
     * @param AMQPMessage $message The message to inject trace context into
     * @param string $routingKey The routing key for the message
     * @param string $exchange The exchange name
     * @return void
     */
    public static function inject(AMQPMessage $message, string $routingKey, string $exchange): void
    {
        if (! config('amqp.otel.enabled')) {
            return;
        }

        $propagator = Globals::propagator();
        $carrier = [];

        // Inject the current trace context into the carrier array
        $propagator->inject($carrier, null, Context::getCurrent());

        // Get existing headers or create new ones
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        if ($headers instanceof AMQPTable) {
            $headerData = $headers->getNativeData();
        } else {
            $headerData = [];
        }

        // Add trace context to headers
        foreach ($carrier as $key => $value) {
            $headerData[$key] = $value;
        }

        // Set the updated headers back to the message
        $message->set('application_headers', new AMQPTable($headerData));

        // Add AMQP metadata as span attributes for better observability
        $span = Span::getCurrent();
        if ($span->isRecording()) {
            $span->setAttribute('messaging.rabbitmq.routing_key', $routingKey);
            $span->setAttribute('messaging.rabbitmq.exchange', $exchange);
            $span->setAttribute('messaging.operation', 'publish');
            $span->setAttribute('messaging.message_payload_size_bytes', strlen($message->getBody()));
        }
    }

    /**
     * Extract trace context from an AMQP message and activate it
     * Also adds AMQP metadata (routing key, exchange, etc.) as span attributes
     * Returns a scope token that MUST be detached after processing to prevent trace leakage
     *
     * @param AMQPMessage $message The message to extract trace context from
     * @return void
     */
    public static function extract(AMQPMessage $message): void
    {
        if (! config('amqp.otel.enabled')) {
            return;
        }

        $propagator = Globals::propagator();

        // Get headers from the message
        $headers = $message->has('application_headers') ? $message->get('application_headers') : null;
        if (! ($headers instanceof AMQPTable)) {
            return;
        }

        $headerData = $headers->getNativeData();

        // Convert AMQP headers format to simple key-value array for propagator
        $carrier = [];
        foreach ($headerData as $key => $value) {
            // AMQP headers are stored as [type, value] arrays
            $carrier[$key] = is_array($value) ? $value[1] : $value;
        }

        // Extract and activate the trace context
        // Returns a scope that MUST be detached to prevent trace leakage
        $context = $propagator->extract($carrier);
        self::$scope = $context->activate();

        // Add AMQP message metadata as span attributes for better observability
        $span = Span::getCurrent();
        if ($span->isRecording()) {
            $span->setAttribute('messaging.operation', 'consume');
            $span->setAttribute('messaging.message_payload_size_bytes', strlen($message->getBody()));
            if ($message->has('routing_key')) {
                $span->setAttribute('messaging.rabbitmq.routing_key', $message->get('routing_key'));
            }
            if ($message->has('exchange')) {
                $span->setAttribute('messaging.rabbitmq.exchange', $message->get('exchange'));
            }
            if ($message->has('consumer_tag')) {
                $span->setAttribute('messaging.rabbitmq.consumer_tag', $message->get('consumer_tag'));
            }
            if ($message->has('redelivered')) {
                $span->setAttribute('messaging.rabbitmq.redelivered', $message->get('redelivered'));
            }
        }
    }

    /**
     * Detach the trace context scope after message processing is complete
     * This prevents trace context from leaking between unrelated messages in long-lived consumers
     *
     * @return void
     */
    public static function detach(): void
    {
        if (self::$scope !== null) {
            self::$scope->detach();
            self::$scope = null;
        }
    }
}
