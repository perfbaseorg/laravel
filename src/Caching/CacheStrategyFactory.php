<?php

namespace Perfbase\Laravel\Caching;

class CacheStrategyFactory
{
    /**
     * Create a new cache strategy instance based on the configuration
     * 
     * @return CacheStrategy|null
     */
    public static function make(): ?CacheStrategy
    {
        return match (config('perfbase.cache')) {
            'database' => new DatabaseStrategy(),
            'file' => new FileStrategy(),
            default => null,
        };
    }
}
