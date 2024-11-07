<?php

namespace Perfbase\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Perfbase\SDK\Client;

/**
 * @method static void startProfiling()
 * @method static void stopProfiling()
 * 
 * @see \Perfbase\SDK\Client
 */
class Perfbase extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return Client::class;
    }
}
