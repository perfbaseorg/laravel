<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Mockery;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\Lifecycle\ConsoleTraceLifecycle;
use Perfbase\Laravel\Lifecycle\HttpTraceLifecycle;
use Perfbase\Laravel\Lifecycle\QueueTraceLifecycle;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\SDK\Config;
use Perfbase\SDK\Extension\ExtensionInterface;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class PublishedConfigContractTest extends TestCase
{
    private PerfbaseClient $perfbaseClient;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        PerfbaseConfig::clearCache();

        $publishedConfig = $this->publishedConfig();
        $publishedConfig['enabled'] = true;
        $publishedConfig['api_key'] = 'test-key';
        $publishedConfig['sample_rate'] = 1.0;
        $publishedConfig['flags'] = 0;
        $publishedConfig['proxy'] = null;
        $publishedConfig['timeout'] = 5;

        $app['config']->set('perfbase', $publishedConfig);
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.version', '1.0.0');
    }

    protected function setUp(): void
    {
        parent::setUp();

        PerfbaseConfig::clearCache();

        $mockExtension = Mockery::mock(ExtensionInterface::class);
        $mockExtension->shouldReceive('isAvailable')->andReturn(true);
        $mockExtension->shouldReceive('startSpan')->andReturn();
        $mockExtension->shouldReceive('stopSpan')->andReturn();
        $mockExtension->shouldReceive('getSpanData')->andReturn('{}');
        $mockExtension->shouldReceive('reset')->andReturn();
        $mockExtension->shouldReceive('setAttribute')->andReturn();

        $this->app->instance(ExtensionInterface::class, $mockExtension);

        $this->perfbaseClient = Mockery::mock(PerfbaseClient::class);
        $this->perfbaseClient->allows('isExtensionAvailable')->andReturns(true);
        $this->perfbaseClient->allows('startTraceSpan');
        $this->perfbaseClient->allows('stopTraceSpan')->andReturns(true);
        $this->perfbaseClient->allows('setAttribute');
        $this->perfbaseClient->allows('submitTrace')->andReturns(SubmitResult::success());
        $this->perfbaseClient->allows('reset');

        $this->app->instance(Config::class, Mockery::mock(Config::class));
        $this->app->instance(PerfbaseClient::class, $this->perfbaseClient);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPublishedFilterContextsMatchImplementedLifecycles(): void
    {
        $publishedConfig = $this->publishedConfig();

        $this->assertSame(['http', 'artisan', 'jobs'], array_keys($publishedConfig['include']));
        $this->assertSame(['http', 'artisan', 'jobs'], array_keys($publishedConfig['exclude']));

        foreach (['console', 'queue', 'schedule', 'exception'] as $unusedContext) {
            $this->assertArrayNotHasKey($unusedContext, $publishedConfig['include']);
            $this->assertArrayNotHasKey($unusedContext, $publishedConfig['exclude']);
        }
    }

    public function testHttpRequestProfilesWithPublishedConfig(): void
    {
        $request = Request::create('/api/users', 'POST');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $lifecycle->setResponse(new Response('', 200));
        $lifecycle->stopProfiling();

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once()->with('http');
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->once()->with('http');
        $this->perfbaseClient->shouldHaveReceived('setAttribute')->with('source', 'http');
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->once();
        $this->assertTrue(true);
    }

    public function testDefaultExcludedHttpRouteDoesNotProfileWithPublishedConfig(): void
    {
        $request = Request::create('/up', 'GET');
        $lifecycle = new HttpTraceLifecycle($request);

        $lifecycle->startProfiling();
        $lifecycle->setResponse(new Response('', 200));
        $lifecycle->stopProfiling();

        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->perfbaseClient->shouldNotHaveReceived('submitTrace');
        $this->assertTrue(true);
    }

    public function testArtisanCommandProfilesWithPublishedConfig(): void
    {
        Event::dispatch(new CommandStarting('migrate', new ArrayInput([]), new NullOutput()));
        Event::dispatch(new CommandFinished('migrate', new ArrayInput([]), new NullOutput(), 0));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once()->with('artisan');
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->once()->with('artisan');
        $this->perfbaseClient->shouldHaveReceived('setAttribute')->with('source', 'artisan');
        $this->perfbaseClient->shouldHaveReceived('setAttribute')->with('action', 'migrate');
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->once();
        $this->assertTrue(true);
    }

    public function testDefaultExcludedQueueWorkCommandDoesNotProfileWithPublishedConfig(): void
    {
        Event::dispatch(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput()));
        Event::dispatch(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput(), 0));

        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->perfbaseClient->shouldNotHaveReceived('submitTrace');
        $this->assertTrue(true);
    }

    public function testQueueJobProfilesWithPublishedConfig(): void
    {
        $job = $this->createMockJob('App\Jobs\SendEmail');

        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once()->with('queue');
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->once()->with('queue');
        $this->perfbaseClient->shouldHaveReceived('setAttribute')->with('source', 'jobs');
        $this->perfbaseClient->shouldHaveReceived('setAttribute')->with('action', 'App\Jobs\SendEmail');
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->once();
        $this->assertTrue(true);
    }

    public function testMissingArtisanIncludePreventsArtisanProfiling(): void
    {
        config(['perfbase.include.artisan' => null]);

        $lifecycle = new ConsoleTraceLifecycle('migrate');
        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->perfbaseClient->shouldNotHaveReceived('submitTrace');
        $this->assertTrue(true);
    }

    public function testMissingJobsIncludePreventsJobProfiling(): void
    {
        config(['perfbase.include.jobs' => null]);

        $lifecycle = new QueueTraceLifecycle('App\Jobs\SendEmail', 'default', 'database');
        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->perfbaseClient->shouldNotHaveReceived('submitTrace');
        $this->assertTrue(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishedConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../config/perfbase.php';
        return $config;
    }

    /**
     * @return Job&\Mockery\MockInterface
     */
    private function createMockJob(string $displayName): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'displayName' => $displayName,
            'data' => ['commandName' => $displayName],
        ]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getJobId')->andReturn('job-1');
        return $job;
    }
}
