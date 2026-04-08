<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Interfaces\ProfiledUser;
use Perfbase\Laravel\Lifecycle\HttpTraceLifecycle;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Mockery;
use ReflectionClass;

class HttpTraceLifecycleTest extends TestCase
{
    private $perfbaseClient;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        PerfbaseConfig::clearCache();

        $this->perfbaseClient = Mockery::mock(PerfbaseClient::class);
        $this->perfbaseClient->allows('isExtensionAvailable')->andReturns(true);
        $this->perfbaseClient->allows('startTraceSpan');
        $this->perfbaseClient->allows('stopTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('setAttribute');
        $this->perfbaseClient->allows('submitTrace')->andReturns(SubmitResult::success());
        $this->perfbaseClient->allows('reset');

        $this->app->instance(Config::class, Mockery::mock(Config::class));
        $this->app->instance(PerfbaseClient::class, $this->perfbaseClient);

        config([
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'test-key',
                'sample_rate' => 1.0,
                'include' => ['http' => ['*']],
                'exclude' => ['http' => []],
            ],
            'app' => ['env' => 'testing', 'version' => '1.0.0'],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSetsHttpAttributes(): void
    {
        $request = Request::create('/api/users', 'POST');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('http', $attrs['source']);
        $this->assertSame('POST', $attrs['http_method']);
        $this->assertStringContainsString('/api/users', $attrs['http_url']);
        $this->assertStringContainsString('POST', $attrs['action']);
    }

    public function testSetsResponseStatusCode(): void
    {
        $request = Request::create('/test', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->setResponse(new Response('', 404));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('404', $attrs['http_status_code']);
    }

    public function testSetsBaseAttributes(): void
    {
        $request = Request::create('/test', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertArrayHasKey('hostname', $attrs);
        $this->assertSame('testing', $attrs['environment']);
        $this->assertSame('1.0.0', $attrs['app_version']);
        $this->assertArrayHasKey('php_version', $attrs);
        $this->assertArrayHasKey('user_ip', $attrs);
        $this->assertArrayHasKey('user_agent', $attrs);
    }

    public function testShouldProfileReturnsFalseWhenDisabled(): void
    {
        config(['perfbase.enabled' => false]);

        $request = Request::create('/test', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileReturnsFalseWhenExcluded(): void
    {
        config([
            'perfbase.include.http' => ['*'],
            'perfbase.exclude.http' => ['GET /excluded*'],
        ]);

        $request = Request::create('/excluded-path', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileReturnsFalseWhenNotIncluded(): void
    {
        config(['perfbase.include.http' => ['POST /api/*']]);

        $request = Request::create('/web/page', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileReturnsFalseWhenExtensionUnavailable(): void
    {
        // Override the default mock with a fresh one that returns false
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(false);
        $client->allows('startTraceSpan');
        $client->allows('stopTraceSpan')->andReturns(false);
        $client->allows('setAttribute');
        $client->allows('submitTrace')->andReturns(SubmitResult::success());
        $client->allows('reset');
        $this->app->instance(PerfbaseClient::class, $client);

        $request = Request::create('/test', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileRespectsProfiledUserInterface(): void
    {
        $user = Mockery::mock(ProfiledUser::class, \Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('shouldBeProfiled')->andReturn(false);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileAllowsUserWithoutInterface(): void
    {
        // User that doesn't implement ProfiledUser — should be allowed
        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertTrue($result);
    }

    /**
     * @return array<string, string>
     */
    private function getAttributes(HttpTraceLifecycle $lifecycle): array
    {
        $reflection = new ReflectionClass($lifecycle);
        $prop = $reflection->getProperty('attributes');
        $prop->setAccessible(true);
        return $prop->getValue($lifecycle);
    }

    private function callShouldProfile(HttpTraceLifecycle $lifecycle): bool
    {
        $reflection = new ReflectionClass($lifecycle);
        $method = $reflection->getMethod('shouldProfile');
        $method->setAccessible(true);
        return $method->invoke($lifecycle);
    }
}
