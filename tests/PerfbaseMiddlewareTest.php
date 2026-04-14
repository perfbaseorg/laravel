<?php

namespace Tests;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Middleware\PerfbaseMiddleware;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Mockery;

class PerfbaseMiddlewareTest extends TestCase
{
    private PerfbaseMiddleware $middleware;
    private $perfbaseClient;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Perfbase config and client
        $config = Mockery::mock(Config::class);
        $this->perfbaseClient = Mockery::mock(PerfbaseClient::class);
        $this->perfbaseClient->allows('isExtensionAvailable')->andReturns(true);
        $this->perfbaseClient->allows('startTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('stopTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('setAttribute')->andReturns(true);
        $this->perfbaseClient->allows('submitTrace')->andReturns(SubmitResult::success());
        $this->perfbaseClient->allows('getTraceData')->andReturns(['trace' => 'data']);
        $this->perfbaseClient->allows('reset')->andReturns(true);

        $this->app->instance(Config::class, $config);
        $this->app->instance(PerfbaseClient::class, $this->perfbaseClient);

        // Set up basic config
        config([
            'perfbase' => [
                'enabled' => true,
                'api_key' => 'test-key',
                'sample_rate' => 1.0,
                'include' => [
                    'http' => ['*'],
                ],
                'exclude' => [
                    'http' => [],
                ],
            ]
        ]);

        $this->middleware = new PerfbaseMiddleware();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleWithProfilingDisabled()
    {
        config(['perfbase.enabled' => false]);

        $request = Request::create('/test', 'GET');
        $expectedResponse = new Response('test response', 200);

        $next = function ($request) use ($expectedResponse) {
            return $expectedResponse;
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertSame($expectedResponse, $response);

        // Verify profiler was not started
        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->perfbaseClient->shouldNotHaveReceived('stopTraceSpan');
    }

    public function testHandleWithProfilingEnabled()
    {
        $request = Request::create('/test', 'GET');
        $expectedResponse = new Response('test response', 200);

        $next = function ($request) use ($expectedResponse) {
            return $expectedResponse;
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertSame($expectedResponse, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleWithDifferentHttpMethods()
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];

        foreach ($methods as $method) {
            $request = Request::create('/test', $method);
            $expectedResponse = new Response('test response', 201);

            $next = function ($request) use ($expectedResponse) {
                return $expectedResponse;
            };

            $response = $this->middleware->handle($request, $next);

            $this->assertSame($expectedResponse, $response);
            $this->assertEquals(201, $response->getStatusCode());
        }
    }

    public function testHandleWithDifferentStatusCodes()
    {
        $statusCodes = [200, 201, 400, 404, 500];

        foreach ($statusCodes as $statusCode) {
            $request = Request::create('/test', 'GET');
            $expectedResponse = new Response('test response', $statusCode);

            $next = function ($request) use ($expectedResponse) {
                return $expectedResponse;
            };

            $response = $this->middleware->handle($request, $next);

            $this->assertEquals($statusCode, $response->getStatusCode());
        }
    }

    public function testHandleUsesRouteUriForActionWhenRouteResolvesDuringRequest()
    {
        $request = Request::create('/media/thumbnail/190476', 'GET');
        $route = $this->makeRoute(['GET'], '/media/{variant}/{asset}');

        $expectedResponse = new Response('test response', 200);

        $next = function ($request) use ($expectedResponse, $route) {
            $request->setRouteResolver(fn() => $route);

            return $expectedResponse;
        };

        [$response, $attributeCalls] = $this->handleRequestAndCaptureAttributes($request, $next);

        $this->assertSame($expectedResponse, $response);
        $this->assertContains(sprintf('GET %s', $route->uri()), $attributeCalls['action'] ?? []);
        $this->assertNotContains('GET media/thumbnail/190476', $attributeCalls['action'] ?? []);
    }

    public function testHandleUsesRouteUriForNestedParameterizedRouteResolvedDuringRequest()
    {
        $request = Request::create('/libraries/main/books/42/chapters/9', 'PATCH');
        $route = $this->makeRoute(
            ['PATCH'],
            '/libraries/{library}/books/{book}/chapters/{chapter}'
        );

        $expectedResponse = new Response('patched', 202);

        $next = function ($request) use ($expectedResponse, $route) {
            $request->setRouteResolver(fn() => $route);

            return $expectedResponse;
        };

        [$response, $attributeCalls] = $this->handleRequestAndCaptureAttributes($request, $next);

        $this->assertSame($expectedResponse, $response);
        $this->assertContains(sprintf('PATCH %s', $route->uri()), $attributeCalls['action'] ?? []);
        $this->assertNotContains('PATCH libraries/main/books/42/chapters/9', $attributeCalls['action'] ?? []);
    }

    public function testHandleUsesRouteUriForWildcardStyleDownloadResolvedDuringRequest()
    {
        $request = Request::create('/downloads/reports/2026/q1-summary.csv', 'GET');
        $route = $this->makeRoute(['GET'], '/downloads/{path}');

        $expectedResponse = new Response('download', 200);

        $next = function ($request) use ($expectedResponse, $route) {
            $request->setRouteResolver(fn() => $route);

            return $expectedResponse;
        };

        [$response, $attributeCalls] = $this->handleRequestAndCaptureAttributes($request, $next);

        $this->assertSame($expectedResponse, $response);
        $this->assertContains(sprintf('GET %s', $route->uri()), $attributeCalls['action'] ?? []);
        $this->assertNotContains('GET downloads/reports/2026/q1-summary.csv', $attributeCalls['action'] ?? []);
    }

    public function testHandleFallsBackToRequestPathWhenNoRouteIsResolved()
    {
        $request = Request::create('/fallback/raw/12345', 'DELETE');
        $expectedResponse = new Response('gone', 204);

        $next = function ($request) use ($expectedResponse) {
            return $expectedResponse;
        };

        [$response, $attributeCalls] = $this->handleRequestAndCaptureAttributes($request, $next);

        $this->assertSame($expectedResponse, $response);
        $this->assertContains('DELETE fallback/raw/12345', $attributeCalls['action'] ?? []);
    }

    public function testHandlePreservesOriginalResponse()
    {
        $request = Request::create('/test', 'POST');
        $headers = ['X-Custom-Header' => 'test-value'];
        $content = json_encode(['data' => 'test']);
        $expectedResponse = new Response($content, 200, $headers);

        $next = function ($request) use ($expectedResponse) {
            return $expectedResponse;
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertSame($expectedResponse, $response);
        $this->assertEquals($content, $response->getContent());
        $this->assertEquals('test-value', $response->headers->get('X-Custom-Header'));
    }

    public function testHandleWithExceptionInNext()
    {
        $request = Request::create('/test', 'GET');
        $exception = new \Exception('Test exception');

        $next = function ($request) use ($exception) {
            throw $exception;
        };

        try {
            $this->middleware->handle($request, $next);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            $this->assertEquals('Test exception', $e->getMessage());
        }
    }

    public function testHandleWithSampleRateZero()
    {
        config(['perfbase.sample_rate' => 0.0]);

        $request = Request::create('/test', 'GET');
        $expectedResponse = new Response('test response', 200);

        $next = function ($request) use ($expectedResponse) {
            return $expectedResponse;
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertSame($expectedResponse, $response);
    }

    /**
     * @return array{0: Response, 1: array<string, array<int, string>>}
     */
    private function handleRequestAndCaptureAttributes(Request $request, callable $next): array
    {
        $attributeCalls = [];
        $client = Mockery::mock(PerfbaseClient::class);
        $client->allows('isExtensionAvailable')->andReturns(true);
        $client->allows('startTraceSpan')->andReturns(true);
        $client->allows('stopTraceSpan')->andReturns(true);
        $client->allows('submitTrace')->andReturns(SubmitResult::success());
        $client->allows('getTraceData')->andReturns(['trace' => 'data']);
        $client->allows('reset')->andReturns(true);
        $client->allows('setAttribute')->andReturnUsing(
            function (string $key, string $value) use (&$attributeCalls): bool {
                $attributeCalls[$key][] = $value;

                return true;
            }
        );

        $this->app->instance(PerfbaseClient::class, $client);

        /** @var Response $response */
        $response = $this->middleware->handle($request, $next);

        return [$response, $attributeCalls];
    }

    private function makeRoute(array $methods, string $uri): Route
    {
        return new Route($methods, $uri, ['controller' => 'SampleAssetController@handle']);
    }
}
