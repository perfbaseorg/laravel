<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Caching\CacheStrategyFactory;
use Perfbase\Laravel\Caching\DatabaseStrategy;
use Perfbase\Laravel\Caching\FileStrategy;
use Perfbase\Laravel\PerfbaseServiceProvider;
use RuntimeException;

class CacheStrategyFactoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    public function testMakeDatabaseStrategy()
    {
        config(['perfbase.sending.mode' => 'database']);
        
        $strategy = CacheStrategyFactory::make();
        
        $this->assertInstanceOf(DatabaseStrategy::class, $strategy);
    }

    public function testMakeFileStrategy()
    {
        config(['perfbase.sending.mode' => 'file']);
        
        $strategy = CacheStrategyFactory::make();
        
        $this->assertInstanceOf(FileStrategy::class, $strategy);
    }

    public function testMakeInvalidStrategyThrowsException()
    {
        config(['perfbase.sending.mode' => 'invalid']);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid cache strategy');
        
        CacheStrategyFactory::make();
    }

    public function testMakeWithSyncModeThrowsException()
    {
        config(['perfbase.sending.mode' => 'sync']);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid cache strategy');
        
        CacheStrategyFactory::make();
    }

    public function testMakeWithNullModeThrowsException()
    {
        config(['perfbase.sending.mode' => null]);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid cache strategy');
        
        CacheStrategyFactory::make();
    }
}