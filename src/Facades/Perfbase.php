<?php

namespace Perfbase\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void startTraceSpan(string $spanName, array<string, string> $attributes = [])
 * @method static bool stopTraceSpan(string $spanName)
 * @method static void submitTrace()
 * @method static string getTraceData(string $spanName = '')
 * @method static void reset()
 * @method static bool isExtensionAvailable()
 * @method static void setAttribute(string $key, string $value)
 * @method static void setFlags(int $flags)
 * 
 * @see \Perfbase\SDK\Perfbase
 */
class Perfbase extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Perfbase\SDK\Perfbase::class;
    }
}