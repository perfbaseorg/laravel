<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
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
        $defaultHttpExcludes = config('perfbase.exclude.http', []);

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
            'perfbase.enabled' => true,
            'perfbase.api_key' => 'test-key',
            'perfbase.sample_rate' => 1.0,
            'perfbase.profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
            'perfbase.include.http' => ['*'],
            'perfbase.exclude.http' => $defaultHttpExcludes,
            'app.env' => 'testing',
            'app.version' => '1.0.0',
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

    public function testUsesRouteUriForActionWhenRouteBecomesAvailableAfterStart(): void
    {
        $request = Request::create('/media/thumbnail/190476', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();

        $route = $this->makeRoute(['GET'], '/media/{variant}/{asset}');
        $request->setRouteResolver(fn() => $route);

        $lifecycle->setResponse(new Response('', 200));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame(sprintf('GET %s', $route->uri()), $attrs['action']);
        $this->assertStringNotContainsString('190476', $attrs['action']);
    }

    public function testUsesRouteUriForNestedParametersWhenRouteBecomesAvailableAfterStart(): void
    {
        $request = Request::create('/collections/alpha/items/42/revisions/7', 'PATCH');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();

        $route = $this->makeRoute(
            ['PATCH'],
            '/collections/{collection}/items/{item}/revisions/{revision}'
        );
        $request->setRouteResolver(fn() => $route);

        $lifecycle->setResponse(new Response('', 202));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame(sprintf('PATCH %s', $route->uri()), $attrs['action']);
        $this->assertStringNotContainsString('/alpha/', $attrs['action']);
        $this->assertStringNotContainsString('/42/', $attrs['action']);
        $this->assertStringNotContainsString('/7', $attrs['action']);
    }

    public function testUsesRouteUriForWildcardStyleDownloadWhenRouteBecomesAvailableAfterStart(): void
    {
        $request = Request::create('/downloads/reports/2026/q1-summary.csv', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();

        $route = $this->makeRoute(['GET'], '/downloads/{path}');
        $request->setRouteResolver(fn() => $route);

        $lifecycle->setResponse(new Response('', 200));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame(sprintf('GET %s', $route->uri()), $attrs['action']);
        $this->assertStringNotContainsString('q1-summary.csv', $attrs['action']);
    }

    public function testKeepsRequestPathAsActionWhenNoRouteIsResolved(): void
    {
        $request = Request::create('/fallback/raw/12345', 'DELETE');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $lifecycle->setResponse(new Response('', 204));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('DELETE fallback/raw/12345', $attrs['action']);
    }

    public function testPrefersRouteUriOverConcretePathWhenRouteIsAvailableBeforeStart(): void
    {
        $request = Request::create('/media/banner/9988', 'GET');
        $route = $this->makeRoute(['GET'], '/media/{placement}/{asset}');
        $request->setRouteResolver(fn() => $route);

        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->startProfiling();

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame(sprintf('GET %s', $route->uri()), $attrs['action']);
        $this->assertStringNotContainsString('9988', $attrs['action']);
    }

    public function testSetsResponseStatusCode(): void
    {
        $request = Request::create('/test', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->setResponse(new Response('', 404));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('404', $attrs['http_status_code']);
    }

    public function testStopProfilingSkipsSubmittingForDisallowedStatusCodeByDefault(): void
    {
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldReceive('startTraceSpan')->once()->with('http');
        $client->shouldReceive('stopTraceSpan')->once()->with('http')->andReturn(true);
        $client->allows('setAttribute');
        $client->shouldNotReceive('submitTrace');
        $client->shouldReceive('reset')->once();
        $this->app->instance(PerfbaseClient::class, $client);

        $request = Request::create('/missing', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $lifecycle->setResponse(new Response('', 404));
        $lifecycle->stopProfiling();

        $this->addToAssertionCount(1);
    }

    public function testStopProfilingSubmitsForServerErrorStatusCodeByDefault(): void
    {
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldReceive('startTraceSpan')->once()->with('http');
        $client->shouldReceive('stopTraceSpan')->once()->with('http')->andReturn(true);
        $client->allows('setAttribute');
        $client->shouldReceive('submitTrace')->once()->andReturn(SubmitResult::success());
        $client->allows('reset');
        $this->app->instance(PerfbaseClient::class, $client);

        $request = Request::create('/explode', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $lifecycle->setResponse(new Response('', 503));
        $lifecycle->stopProfiling();

        $this->addToAssertionCount(1);
    }

    public function testStopProfilingSubmitsForCustomAllowedStatusCode(): void
    {
        config(['perfbase.profile_http_status_codes' => [200, 404]]);
        PerfbaseConfig::clearCache();

        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldReceive('startTraceSpan')->once()->with('http');
        $client->shouldReceive('stopTraceSpan')->once()->with('http')->andReturn(true);
        $client->allows('setAttribute');
        $client->shouldReceive('submitTrace')->once()->andReturn(SubmitResult::success());
        $client->allows('reset');
        $this->app->instance(PerfbaseClient::class, $client);

        $request = Request::create('/missing', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $lifecycle->setResponse(new Response('', 404));
        $lifecycle->stopProfiling();

        $this->addToAssertionCount(1);
    }

    public function testStartProfilingUsesHttpSpanName(): void
    {
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->shouldReceive('startTraceSpan')->once()->with('http');
        $client->allows('stopTraceSpan')->andReturns(true);
        $client->allows('setAttribute');
        $client->allows('submitTrace')->andReturns(SubmitResult::success());
        $client->allows('reset');
        $this->app->instance(PerfbaseClient::class, $client);

        $request = Request::create('/api/users/123', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $this->addToAssertionCount(1);
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

    public function testSetsAuthenticatedUserIdFromRequestUser(): void
    {
        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('uuid');
        $user->shouldReceive('getAuthIdentifier')->andReturn('user-123');

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $user);

        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->startProfiling();
        $this->assertArrayNotHasKey('user_id', $this->getAttributes($lifecycle));

        $lifecycle->setResponse(new Response('', 200));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('user-123', $attrs['user_id']);
    }

    public function testSetsAuthenticatedUserIdWhenUserBecomesAvailableAfterProfilingStarts(): void
    {
        $user = Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('uuid');
        $user->shouldReceive('getAuthIdentifier')->andReturn('user-456');

        $request = Request::create('/test', 'GET');

        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->startProfiling();

        $request->setUserResolver(fn() => $user);
        $lifecycle->setResponse(new Response('', 200));

        $attrs = $this->getAttributes($lifecycle);
        $this->assertSame('user-456', $attrs['user_id']);
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

    public function testShouldProfileReturnsTrueWhenIncludedByRouteName(): void
    {
        config([
            'perfbase.include.http' => ['admin.users.*'],
            'perfbase.exclude.http' => [],
        ]);

        $request = Request::create('/admin/users', 'GET');
        $request->setRouteResolver(fn() => $this->makeRoute(['GET'], '/admin/users', 'admin.users.index'));

        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertTrue($result);
    }

    public function testShouldProfileReturnsFalseWhenRouteNameDoesNotMatchIncludedPatterns(): void
    {
        config([
            'perfbase.include.http' => ['api.orders.*'],
            'perfbase.exclude.http' => [],
        ]);

        $request = Request::create('/admin/users', 'GET');
        $request->setRouteResolver(fn() => $this->makeRoute(['GET'], '/admin/users', 'admin.users.index'));

        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileReturnsFalseWhenExcludedByRouteName(): void
    {
        config([
            'perfbase.include.http' => ['*'],
            'perfbase.exclude.http' => ['admin.users.*'],
        ]);

        $request = Request::create('/admin/users', 'GET');
        $request->setRouteResolver(fn() => $this->makeRoute(['GET'], '/admin/users', 'admin.users.index'));

        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertFalse($result);
    }

    public function testShouldProfileAllowsUnnamedNonNoiseRouteWithDefaultConfig(): void
    {
        $request = Request::create('/api/users', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $result = $this->callShouldProfile($lifecycle);
        $this->assertTrue($result);
    }

    public function testShouldProfileReturnsFalseForDefaultFrameworkNoiseRoutes(): void
    {
        $paths = [
            '/up',
            '/sanctum/csrf-cookie',
            '/telescope/requests',
            '/horizon/jobs',
            '/pulse/stats',
            '/livewire/update',
            '/_ignition/health-check',
        ];

        foreach ($paths as $path) {
            $request = Request::create($path, 'GET');
            $lifecycle = new HttpTraceLifecycle($request);

            $result = $this->callShouldProfile($lifecycle);
            $this->assertFalse($result, sprintf('Expected "%s" to be excluded by default.', $path));
        }
    }

    public function testShouldProfileReturnsFalseForOptionsRequestsByDefault(): void
    {
        $request = Request::create('/api/users', 'OPTIONS');
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

    private function makeRoute(array $methods, string $uri, ?string $name = null): Route
    {
        $route = new Route($methods, $uri, ['controller' => 'SampleAssetController@handle']);

        if ($name !== null) {
            $route->name($name);
        }

        return $route;
    }
}
