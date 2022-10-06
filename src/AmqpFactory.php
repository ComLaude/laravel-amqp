<?php
namespace ComLaude\Amqp;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpFactory
{
    /**
     * Reference to all open channels, defaults to opening the
     * config based channel and route non-overriden requests through it
     */
    private static $channels = [];

    /**
     * Creates a channel instance or returns an already open channel
     *
     * @param array $properties
     * @return AmqpChannel
     */
    public static function create(array $properties = [], array $base = null)
    {
        // Merge properties with config
        if (empty($base)) {
            $base = config('amqp.properties.' . config('amqp.use'));
        }
        $final = array_merge($base, $properties);
        // Try to find a matching channel first
        if (isset(self::$channels[$final['exchange'] . '.' . $final['queue']])) {
            return self::$channels[$final['exchange'] . '.' . $final['queue']];
        }
        return self::$channels[$final['exchange'] . '.' . $final['queue']] = new AmqpChannel($final);
    }

    public static function clear($properties)
    {
        if (! empty($properties['exchange']) && ! empty($properties['queue'])) {
            unset(self::$channels[$properties['exchange'] . '.' . $properties['queue']]);
        }
    }
}
