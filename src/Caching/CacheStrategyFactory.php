<?php

namespace Perfbase\Laravel\Caching;

use RuntimeException;

class CacheStrategyFactory
{
    /**
     * Create a new cache strategy instance based on the configuration
     *
     * @return CacheStrategy
     */
    public static function make(): CacheStrategy
    {
        switch (config('perfbase.cache.enabled')) {
            case 'database':
                return new DatabaseStrategy();
            case 'file':
                return new FileStrategy();
            default:
                throw new RuntimeException('Invalid cache strategy');
        }
    }
}
