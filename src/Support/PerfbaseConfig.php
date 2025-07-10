<?php

namespace Perfbase\Laravel\Support;

/**
 * Cached configuration access for Perfbase
 */
class PerfbaseConfig
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * Get a configuration value with caching
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (self::$cache === null) {
            self::$cache = config('perfbase', []);
        }
        
        return data_get(self::$cache, $key, $default);
    }

    /**
     * Check if profiling is enabled
     *
     * @return bool
     */
    public static function enabled(): bool
    {
        return self::get('enabled', false);
    }

    /**
     * Get the sample rate
     *
     * @return float
     */
    public static function sampleRate(): float
    {
        return self::get('sample_rate', 1.0);
    }

    /**
     * Get the sending mode
     *
     * @return string
     */
    public static function sendingMode(): string
    {
        return self::get('sending.mode', 'sync');
    }

    /**
     * Clear the cache (useful for testing)
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}