<?php

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Perfbase\Laravel\Support\SpanNaming;
use PHPUnit\Framework\TestCase;

class SpanNamingTest extends TestCase
{
    public function testGenerateBasicSpanName()
    {
        $spanName = SpanNaming::generate('test', 'identifier');
        
        $this->assertEquals('test.identifier', $spanName);
    }

    public function testForHttpWithSimpleRequest()
    {
        $request = Request::create('/api/users', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.GET./api/users', $spanName);
    }

    public function testForHttpWithPostRequest()
    {
        $request = Request::create('/api/users', 'POST');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.POST./api/users', $spanName);
    }

    public function testForHttpWithRootPath()
    {
        $request = Request::create('/', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.GET./', $spanName);
    }

    public function testForHttpWithPathNormalization()
    {
        $request = Request::create('api/users', 'GET'); // No leading slash
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.GET./api/users', $spanName);
    }

    public function testForHttpWithComplexPath()
    {
        $request = Request::create('/api/v1/users/123/profile', 'PUT');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.PUT./api/v1/users/123/profile', $spanName);
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
        
        $this->assertEquals('http.GET./api/users/{id}', $spanName);
    }

    public function testForHttpWithQueryParameters()
    {
        $request = Request::create('/api/users?page=1&limit=10', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.GET./api/users', $spanName);
    }

    public function testForHttpWithSpecialCharacters()
    {
        $request = Request::create('/api/users-list_endpoint', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.GET./api/users-list_endpoint', $spanName);
    }

    public function testForConsoleWithSimpleCommand()
    {
        $spanName = SpanNaming::forConsole('migrate');
        
        $this->assertEquals('console.migrate', $spanName);
    }

    public function testForConsoleWithNamespacedCommand()
    {
        $spanName = SpanNaming::forConsole('migrate:fresh');
        
        $this->assertEquals('console.migrate:fresh', $spanName);
    }

    public function testForConsoleWithComplexCommand()
    {
        $spanName = SpanNaming::forConsole('queue:work --queue=high,default');
        
        $this->assertEquals('console.queue:work --queue=high,default', $spanName);
    }

    public function testForConsoleWithEmptyCommand()
    {
        $spanName = SpanNaming::forConsole('');
        
        $this->assertEquals('console.', $spanName);
    }

    public function testForQueueWithSimpleJobClass()
    {
        $spanName = SpanNaming::forQueue('ProcessPodcast');
        
        $this->assertEquals('queue.ProcessPodcast', $spanName);
    }

    public function testForQueueWithNamespacedJobClass()
    {
        $spanName = SpanNaming::forQueue('App\\Jobs\\ProcessPodcast');
        
        $this->assertEquals('queue.ProcessPodcast', $spanName);
    }

    public function testForQueueWithDeepNamespacedJobClass()
    {
        $spanName = SpanNaming::forQueue('App\\Jobs\\Email\\SendWelcomeEmail');
        
        $this->assertEquals('queue.SendWelcomeEmail', $spanName);
    }

    public function testForQueueWithComplexJobClass()
    {
        $spanName = SpanNaming::forQueue('App\\Jobs\\Reports\\GenerateMonthlyReport');
        
        $this->assertEquals('queue.GenerateMonthlyReport', $spanName);
    }

    public function testForQueueWithEmptyJobName()
    {
        $spanName = SpanNaming::forQueue('');
        
        $this->assertEquals('queue.', $spanName);
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
        
        // All should follow type.identifier format
        $this->assertStringContainsString('.', $httpSpan);
        $this->assertStringContainsString('.', $consoleSpan);
        $this->assertStringContainsString('.', $queueSpan);
        $this->assertStringContainsString('.', $databaseSpan);
        $this->assertStringContainsString('.', $cacheSpan);
        
        // Should start with the correct type
        $this->assertStringStartsWith('http.', $httpSpan);
        $this->assertStringStartsWith('console.', $consoleSpan);
        $this->assertStringStartsWith('queue.', $queueSpan);
        $this->assertStringStartsWith('database.', $databaseSpan);
        $this->assertStringStartsWith('cache.', $cacheSpan);
    }

    public function testSpanNameLengthLimits()
    {
        // Test with very long identifiers
        $longPath = str_repeat('/very-long-path-segment', 20);
        $request = Request::create($longPath, 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        // Should still work with long paths
        $this->assertStringStartsWith('http.GET.', $spanName);
        $this->assertStringContainsString($longPath, $spanName);
    }

    public function testSpanNameWithSpecialCharacters()
    {
        $request = Request::create('/api/users/@me/profile', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        $this->assertEquals('http.GET./api/users/@me/profile', $spanName);
    }

    public function testSpanNameWithUnicodeCharacters()
    {
        $request = Request::create('/api/users/测试', 'GET');
        
        $spanName = SpanNaming::forHttp($request);
        
        // Unicode handling may vary by environment, so just check the prefix
        $this->assertStringStartsWith('http.GET./api/users/', $spanName);
        $this->assertStringContainsString('/api/users/', $spanName);
    }
}