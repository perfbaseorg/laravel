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
     * Get the HTTP status codes that should be submitted.
     *
     * @return array<int>
     */
    public static function profileHttpStatusCodes(): array
    {
        $statusCodes = self::get('profile_http_status_codes', [...range(200, 299), ...range(500, 599)]);
        if (!is_array($statusCodes)) {
            return [...range(200, 299), ...range(500, 599)];
        }

        $normalized = [];

        foreach ($statusCodes as $statusCode) {
            if (is_int($statusCode)) {
                $normalized[] = $statusCode;
                continue;
            }

            if (is_string($statusCode) && ctype_digit($statusCode)) {
                $normalized[] = (int) $statusCode;
            }
        }

        return array_values(array_unique($normalized));
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
