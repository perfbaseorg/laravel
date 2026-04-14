<?php

namespace Perfbase\Laravel\Support;

use Illuminate\Http\Request;

/**
 * Standardized span naming utility
 */
class SpanNaming
{
    private const HTTP_SPAN_NAME = 'http';
    private const ARTISAN_SPAN_NAME = 'artisan';
    private const QUEUE_SPAN_NAME = 'queue';

    /**
     * Generate a standardized span name
     * Format: {type}.{identifier}
     *
     * @param string $type
     * @param string $identifier
     * @return string
     */
    public static function generate(string $type, string $identifier): string
    {
        return "{$type}.{$identifier}";
    }

    /**
     * Generate HTTP span name.
     *
     * Keep lifecycle span names SDK-safe and low-cardinality.
     * Route detail belongs in trace attributes, not the span identifier.
     *
     * @param Request $request
     * @return string
     */
    public static function forHttp(Request $request): string
    {
        return self::HTTP_SPAN_NAME;
    }

    /**
     * Generate artisan span name.
     *
     * @param string $command
     * @return string
     */
    public static function forConsole(string $command): string
    {
        return self::ARTISAN_SPAN_NAME;
    }

    /**
     * Generate queue span name.
     *
     * @param string $jobName
     * @return string
     */
    public static function forQueue(string $jobName): string
    {
        return self::QUEUE_SPAN_NAME;
    }

    /**
     * Generate database span name
     * Format: database.{operation}
     *
     * @param string $operation
     * @return string
     */
    public static function forDatabase(string $operation): string
    {
        return self::generate('database', $operation);
    }

    /**
     * Generate cache span name
     * Format: cache.{operation}
     *
     * @param string $operation
     * @return string
     */
    public static function forCache(string $operation): string
    {
        return self::generate('cache', $operation);
    }
}
