<?php

namespace Perfbase\Laravel\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Standardized span naming utility
 */
class SpanNaming
{
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
     * Generate HTTP span name
     * Format: http.{METHOD}.{path}
     *
     * @param Request $request
     * @return string
     */
    public static function forHttp(Request $request): string
    {
        $method = $request->method();
        $path = $request->path();
        
        // Use route URI if available for better consistency
        $route = $request->route();
        if ($route instanceof Route) {
            $path = $route->uri();
        }
        
        // Ensure path starts with /
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        return self::generate('http', "{$method}.{$path}");
    }

    /**
     * Generate console span name
     * Format: console.{command}
     *
     * @param string $command
     * @return string
     */
    public static function forConsole(string $command): string
    {
        return self::generate('console', $command);
    }

    /**
     * Generate queue span name
     * Format: queue.{JobClass}
     *
     * @param string $jobName
     * @return string
     */
    public static function forQueue(string $jobName): string
    {
        // Extract class name from full namespace
        $className = class_basename($jobName);
        
        return self::generate('queue', $className);
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