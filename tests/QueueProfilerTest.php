<?php

use Illuminate\Contracts\Queue\Job;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Profiling\QueueProfiler;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;

class QueueProfilerTest extends TestCase
{
    private QueueProfiler $profiler;
    private Job $job;
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
                    'queue' => [],
                ],
                'exclude' => [
                    'queue' => [],
                ],
            ]
        ]);

        // Mock the job
        $this->job = Mockery::mock(Job::class);
        $this->job->shouldReceive('getName')->andReturn('App\Jobs\TestJob');
        $this->job->shouldReceive('getQueue')->andReturn('default');
        $this->job->shouldReceive('attempts')->andReturn(1);
        $this->job->shouldReceive('getConnectionName')->andReturn('redis');
        $this->job->shouldReceive('getJobId')->andReturn('123');

        $this->profiler = new QueueProfiler($this->job, 'App\Jobs\TestJob');
        $this->reflection = new ReflectionClass(QueueProfiler::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConstructor()
    {
        $this->assertEquals('App\Jobs\TestJob', $this->getPrivateProperty('spanName'));
    }

    public function testShouldProfileWhenDisabled()
    {
        config(['perfbase.enabled' => false]);
        $this->assertFalse($this->callPrivateMethod('shouldProfile'));
    }

    public function testShouldProfileWhenEnabled()
    {
        config(['perfbase.enabled' => true]);
        config(['perfbase.include.queue' => ['App\Jobs\TestJob']]);
        config(['perfbase.exclude.queue' => []]);

        $this->assertTrue($this->callPrivateMethod('shouldProfile'));
    }

    public function testGetJobName()
    {
        $this->assertEquals('App\Jobs\TestJob', $this->getPrivateProperty('jobName'));
    }

    public function testSetDefaultAttributes()
    {
        $this->callPrivateMethod('setDefaultAttributes');
        $attributes = $this->getPrivateProperty('attributes');

        $this->assertEquals('default', $attributes['queue']);
        $this->assertEquals('App\Jobs\TestJob', $attributes['action']);
        $this->assertEquals('1', $attributes['attempts']);
        $this->assertEquals('redis', $attributes['connection']);
        $this->assertEquals('123', $attributes['job_id']);
    }

    public function testSetException()
    {
        $this->profiler->setException('Test exception');
        $this->assertEquals('Test exception', $this->getPrivateProperty('attributes')['exception']);
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
