<?php

namespace Tests;

require_once __DIR__ . '/TestHelpers.php';

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\PerfbaseConfig;
use Perfbase\SDK\Config;
use Perfbase\SDK\Extension\ExtensionInterface;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\SubmitResult;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * End-to-end tests that fire real Laravel events and verify the
 * ServiceProvider wiring creates lifecycle instances and calls
 * start -> stop -> submit through the actual event flow.
 */
class EventFlowTest extends TestCase
{
    private $perfbaseClient;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    /**
     * Set config BEFORE the service provider boots so event listeners are registered.
     */
    protected function defineEnvironment($app): void
    {
        // Clear static cache before boot so prior test state doesn't leak
        PerfbaseConfig::clearCache();

        $app['config']->set('perfbase.enabled', true);
        $app['config']->set('perfbase.api_key', 'test-key');
        $app['config']->set('perfbase.sample_rate', 1.0);
        $app['config']->set('perfbase.flags', 0);
        $app['config']->set('perfbase.proxy', null);
        $app['config']->set('perfbase.timeout', 5);
        $app['config']->set('perfbase.include.http', ['*']);
        $app['config']->set('perfbase.include.console', ['*']);
        $app['config']->set('perfbase.include.queue', ['*']);
        $app['config']->set('perfbase.exclude.http', []);
        $app['config']->set('perfbase.exclude.console', []);
        $app['config']->set('perfbase.exclude.queue', []);
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

    // ---------------------------------------------------------------
    // Queue event flow
    // ---------------------------------------------------------------

    public function testQueueJobProfilingThroughEvents(): void
    {
        $job = $this->createMockJob('App\Jobs\SendEmail');

        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once();
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->once();
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->once();
        $this->assertTrue(true);
    }

    public function testQueueJobExceptionStillSubmits(): void
    {
        $job = $this->createMockJob('App\Jobs\FailingJob');
        $exception = new \RuntimeException('Job failed');

        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobExceptionOccurred('database', $job, $exception));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once();
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->once();
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->once();
        $this->perfbaseClient->shouldHaveReceived('setAttribute')
            ->with('exception', 'Job failed');
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Console event flow
    // ---------------------------------------------------------------

    public function testConsoleCommandProfilingThroughEvents(): void
    {
        $input = new ArrayInput([]);
        $output = new NullOutput();

        Event::dispatch(new CommandStarting('migrate', $input, $output));
        Event::dispatch(new CommandFinished('migrate', $input, $output, 0));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once();
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->once();
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->once();
        $this->assertTrue(true);
    }

    public function testConsoleCommandExitCodeIsRecorded(): void
    {
        $input = new ArrayInput([]);
        $output = new NullOutput();

        Event::dispatch(new CommandStarting('migrate', $input, $output));
        Event::dispatch(new CommandFinished('migrate', $input, $output, 1));

        $this->perfbaseClient->shouldHaveReceived('setAttribute')
            ->with('exit_code', '1');
        $this->assertTrue(true);
    }

    public function testNullCommandIsIgnored(): void
    {
        $event = new CommandStarting(null, new ArrayInput([]), new NullOutput());
        Event::dispatch($event);

        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Sample rate
    // ---------------------------------------------------------------

    public function testSampleRateZeroSkipsStartProfiling(): void
    {
        config(['perfbase.sample_rate' => 0.0]);

        $job = $this->createMockJob('App\Jobs\SomeJob');
        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));

        // startTraceSpan should never be called because sample rate check fails
        $this->perfbaseClient->shouldNotHaveReceived('startTraceSpan');
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Multiple concurrent jobs
    // ---------------------------------------------------------------

    public function testTwoConcurrentJobsTrackedIndependently(): void
    {
        $job1 = $this->createMockJob('App\Jobs\JobA', 'job-1');
        $job2 = $this->createMockJob('App\Jobs\JobB', 'job-2');

        // Start both
        Event::dispatch(new JobProcessing('database', $job1));
        Event::dispatch(new JobProcessing('database', $job2));

        // Finish in reverse order
        Event::dispatch(new JobProcessed('database', $job2));
        Event::dispatch(new JobProcessed('database', $job1));

        // Both should have been profiled
        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->twice();
        $this->perfbaseClient->shouldHaveReceived('stopTraceSpan')->twice();
        $this->perfbaseClient->shouldHaveReceived('submitTrace')->twice();
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Job name fallbacks
    // ---------------------------------------------------------------

    public function testJobNameFallsBackToCommandName(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'data' => ['commandName' => 'App\Jobs\ViaCommandName'],
        ]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getJobId')->andReturn('fallback-1');

        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true);
    }

    public function testJobNameFallsBackToPayloadJob(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'job' => 'App\Jobs\ViaPayloadJob',
            'data' => [],
        ]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getJobId')->andReturn('fallback-2');

        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true);
    }

    public function testJobNameFallsBackToUnknown(): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['data' => []]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getJobId')->andReturn('fallback-3');

        Event::dispatch(new JobProcessing('database', $job));
        Event::dispatch(new JobProcessed('database', $job));

        $this->perfbaseClient->shouldHaveReceived('startTraceSpan')->once();
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @return Job&\Mockery\MockInterface
     */
    private function createMockJob(string $displayName, string $jobId = '1'): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn([
            'displayName' => $displayName,
            'data' => ['commandName' => $displayName],
        ]);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getJobId')->andReturn($jobId);
        return $job;
    }
}
