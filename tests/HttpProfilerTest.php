<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Interfaces\ProfiledUser;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Profiling\HttpProfiler;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;
use Mockery;

class HttpProfilerTest extends TestCase
{
    private HttpProfiler $profiler;
    private Request $request;
    private ReflectionClass $reflection;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Perfbase config and client
        $config = Mockery::mock(Config::class);
        $perfbaseClient = Mockery::mock(PerfbaseClient::class);
        $perfbaseClient->allows('isAvailable')->andReturns(true);
        $this->app->instance(Config::class, $config);
        $this->app->instance(PerfbaseClient::class, $perfbaseClient);

        // Set up basic config values needed for the test
        config([
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'test-key',
                'sample_rate' => 1.0,
                'sending' => [
                    'mode' => 'sync',
                    'timeout' => 5,
                ],
                'include' => [
                    'http' => [],
                ],
                'exclude' => [
                    'http' => [],
                ],
            ]
        ]);

        $this->request = new Request();
        $this->request->server->set('SERVER_NAME', 'localhost');
        $this->request->server->set('SERVER_PORT', 80);
        $this->profiler = new HttpProfiler($this->request);
        $this->reflection = new ReflectionClass(HttpProfiler::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConstructor()
    {
        $this->assertEquals('http', $this->getPrivateProperty('spanName'));
    }

    public function testSetResponse()
    {
        $response = new Response('', 200);
        $this->profiler->setResponse($response);
        $this->assertEquals('200', $this->getPrivateProperty('attributes')['http_status_code']);
    }

    public function testShouldProfileWhenDisabled()
    {
        config(['perfbase.enabled' => false]);
        $this->assertFalse($this->callPrivateMethod('shouldProfile'));
    }

//    public function testShouldProfileWhenEnabled()
//    {
//        config(['perfbase.enabled' => true]);
//        config(['perfbase.include.http' => ['*']]);
//        config(['perfbase.exclude.http' => []]);
//
//        $this->assertTrue($this->callPrivateMethod('shouldProfile'));
//    }

    public function testShouldUserBeProfiled()
    {
        $user = new class implements Authenticatable, ProfiledUser {
            public function shouldBeProfiled(): bool
            {
                return true;
            }

            public function getAuthIdentifierName()
            {
                // No op
            }

            public function getAuthIdentifier()
            {
                // No op
            }

            public function getAuthPassword()
            {
                // No op
            }

            public function getRememberToken()
            {
                // No op
            }

            public function setRememberToken($value)
            {
                // No op
            }

            public function getRememberTokenName()
            {
                // No op
            }
        };

        $this->assertTrue($this->callPrivateMethod('shouldUserBeProfiled', [$user]));
    }

    public function testGetRequestComponents()
    {
        $this->request->setMethod('GET');
        $this->request->server->set('REQUEST_URI', '/test');

        $components = $this->callPrivateMethod('getRequestComponents');

        $this->assertContains('GET /test', $components);
        $this->assertContains('/test', $components);
    }

    public function testShouldRouteBeProfiled()
    {
        $components = ['GET /test'];
        config(['perfbase.include.http' => ['GET /test']]);
        config(['perfbase.exclude.http' => []]);

        $this->assertTrue($this->callPrivateMethod('shouldRouteBeProfiled', [$components]));
    }

    public function testMatchesIncludeFilters()
    {
        $components = ['GET /test'];
        config(['perfbase.include.http' => ['GET /test']]);

        $this->assertTrue($this->callPrivateMethod('matchesIncludeFilters', [$components]));
    }

    public function testMatchesExcludeFilters()
    {
        $components = ['GET /test'];
        config(['perfbase.exclude.http' => ['GET /test']]);

        $this->assertTrue($this->callPrivateMethod('matchesExcludeFilters', [$components]));
    }

    public function testSetDefaultAttributes()
    {
        $this->request->setMethod('GET');
        $this->request->server->set('REQUEST_URI', '/test');
        $this->request->server->set('SERVER_NAME', 'localhost');
        $this->request->server->set('SERVER_PORT', 80);

        $this->callPrivateMethod('setDefaultAttributes');
        $attributes = $this->getPrivateProperty('attributes');

        $this->assertEquals('GET', $attributes['http_method']);
        $this->assertEquals('http://localhost/test', $attributes['http_url']);
    }

    private function callPrivateMethod(string $methodName, array $args = [])
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->profiler, $args);
    }

    private function getPrivateProperty(string $propertyName)
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($this->profiler);
    }
}
