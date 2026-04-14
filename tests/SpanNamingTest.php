<?php

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Perfbase\Laravel\Support\SpanNaming;
use PHPUnit\Framework\TestCase;

class SpanNamingTest extends TestCase
{
    private const SDK_SPAN_NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function testGenerateBasicSpanName()
    {
        $spanName = SpanNaming::generate('test', 'identifier');
        
        $this->assertEquals('test.identifier', $spanName);
    }

    public function testForHttpWithSimpleRequest()
    {
        $request = Request::create('/api/users', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithPostRequest()
    {
        $request = Request::create('/api/users', 'POST');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithRootPath()
    {
        $request = Request::create('/', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithPathNormalization()
    {
        $request = Request::create('api/users', 'GET'); // No leading slash
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithComplexPath()
    {
        $request = Request::create('/api/v1/users/123/profile', 'PUT');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithRoute()
    {
        $request = Request::create('/api/users/123', 'GET');
        
        // Mock a route
        $route = new Route(['GET'], '/api/users/{id}', ['controller' => 'UserController@show']);
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithQueryParameters()
    {
        $request = Request::create('/api/users?page=1&limit=10', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForHttpWithSpecialCharacters()
    {
        $request = Request::create('/api/users-list_endpoint', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testForConsoleWithSimpleCommand()
    {
        $spanName = SpanNaming::forConsole('migrate');
        
        $this->assertEquals('artisan', $spanName);
    }

    public function testForConsoleWithNamespacedCommand()
    {
        $spanName = SpanNaming::forConsole('migrate:fresh');
        
        $this->assertEquals('artisan', $spanName);
    }

    public function testForConsoleWithComplexCommand()
    {
        $spanName = SpanNaming::forConsole('queue:work --queue=high,default');
        
        $this->assertEquals('artisan', $spanName);
    }

    public function testForConsoleWithEmptyCommand()
    {
        $spanName = SpanNaming::forConsole('');
        
        $this->assertEquals('artisan', $spanName);
    }

    public function testForQueueWithSimpleJobClass()
    {
        $spanName = SpanNaming::forQueue('ProcessPodcast');
        
        $this->assertEquals('queue', $spanName);
    }

    public function testForQueueWithNamespacedJobClass()
    {
        $spanName = SpanNaming::forQueue('App\\Jobs\\ProcessPodcast');
        
        $this->assertEquals('queue', $spanName);
    }

    public function testForQueueWithDeepNamespacedJobClass()
    {
        $spanName = SpanNaming::forQueue('App\\Jobs\\Email\\SendWelcomeEmail');
        
        $this->assertEquals('queue', $spanName);
    }

    public function testForQueueWithComplexJobClass()
    {
        $spanName = SpanNaming::forQueue('App\\Jobs\\Reports\\GenerateMonthlyReport');
        
        $this->assertEquals('queue', $spanName);
    }

    public function testForQueueWithEmptyJobName()
    {
        $spanName = SpanNaming::forQueue('');
        
        $this->assertEquals('queue', $spanName);
    }

    public function testForDatabaseWithSelectOperation()
    {
        $spanName = SpanNaming::forDatabase('SELECT');
        
        $this->assertEquals('database.SELECT', $spanName);
    }

    public function testForDatabaseWithInsertOperation()
    {
        $spanName = SpanNaming::forDatabase('INSERT');
        
        $this->assertEquals('database.INSERT', $spanName);
    }

    public function testForDatabaseWithComplexOperation()
    {
        $spanName = SpanNaming::forDatabase('UPDATE users SET active = 1');
        
        $this->assertEquals('database.UPDATE users SET active = 1', $spanName);
    }

    public function testForCacheWithGetOperation()
    {
        $spanName = SpanNaming::forCache('get');
        
        $this->assertEquals('cache.get', $spanName);
    }

    public function testForCacheWithSetOperation()
    {
        $spanName = SpanNaming::forCache('set');
        
        $this->assertEquals('cache.set', $spanName);
    }

    public function testForCacheWithComplexOperation()
    {
        $spanName = SpanNaming::forCache('remember:user.123');
        
        $this->assertEquals('cache.remember:user.123', $spanName);
    }

    public function testForCacheWithEmptyOperation()
    {
        $spanName = SpanNaming::forCache('');
        
        $this->assertEquals('cache.', $spanName);
    }

    public function testConsistentFormatAcrossTypes()
    {
        $httpSpan = SpanNaming::forHttp(Request::create('/test', 'GET'));
        $consoleSpan = SpanNaming::forConsole('test:command');
        $queueSpan = SpanNaming::forQueue('TestJob');
        $databaseSpan = SpanNaming::forDatabase('SELECT');
        $cacheSpan = SpanNaming::forCache('get');
        
        // Trace span names must remain SDK-safe.
        $this->assertMatchesRegularExpression(self::SDK_SPAN_NAME_PATTERN, $httpSpan);
        $this->assertMatchesRegularExpression(self::SDK_SPAN_NAME_PATTERN, $consoleSpan);
        $this->assertMatchesRegularExpression(self::SDK_SPAN_NAME_PATTERN, $queueSpan);
        $this->assertStringContainsString('.', $databaseSpan);
        $this->assertStringContainsString('.', $cacheSpan);
        
        // Lifecycle span names are low-cardinality constants.
        $this->assertSame('http', $httpSpan);
        $this->assertSame('artisan', $consoleSpan);
        $this->assertSame('queue', $queueSpan);
        $this->assertStringStartsWith('database.', $databaseSpan);
        $this->assertStringStartsWith('cache.', $cacheSpan);
    }

    public function testSpanNameLengthLimits()
    {
        // Test with very long identifiers
        $longPath = str_repeat('/very-long-path-segment', 20);
        $request = Request::create($longPath, 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertSame('http', $spanName);
    }

    public function testSpanNameWithSpecialCharacters()
    {
        $request = Request::create('/api/users/@me/profile', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http', $spanName);
    }

    public function testSpanNameWithUnicodeCharacters()
    {
        $request = Request::create('/api/users/测试', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertSame('http', $spanName);
    }

    public function testLifecycleSpanNamesRemainSdkSafe(): void
    {
        $spanNames = [
            SpanNaming::forHttp(Request::create('/api/users/{id}', 'GET')),
            SpanNaming::forConsole('queue:work --queue=high,default'),
            SpanNaming::forQueue('App\\Jobs\\Nested\\DeepJob'),
        ];

        foreach ($spanNames as $spanName) {
            $this->assertMatchesRegularExpression(self::SDK_SPAN_NAME_PATTERN, $spanName);
        }
    }
}
