<?php

namespace Tests;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Profiling\ConsoleProfiler;
use Perfbase\SDK\Config;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Mockery;

class ConsoleProfilerTest extends TestCase
{
    private ConsoleProfiler $profiler;
    private Command $command;
    private ReflectionClass $reflection;
    private ArrayInput $input;
    private ConsoleOutput $output;

    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    /**
     * @throws BindingResolutionException
     */
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
                    'console' => [],
                ],
                'exclude' => [
                    'console' => [],
                ],
            ]
        ]);

        $this->command = new class extends Command {
            protected $signature = 'test:command {arg} {--option=}';
            public function handle() {}
        };

        $this->input = new ArrayInput(['arg' => 'value', '--option' => 'opt']);
        $this->output = new ConsoleOutput();
        $this->output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

        $this->profiler = new ConsoleProfiler(
            $this->command,
            $this->input,
            $this->output
        );
        $this->reflection = new ReflectionClass(ConsoleProfiler::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testConstructor()
    {
        $this->assertEquals('console', $this->getPrivateProperty('spanName'));
    }

    public function testSetExitCode()
    {
        $this->profiler->setExitCode(1);
        $this->assertEquals('1', $this->getPrivateProperty('attributes')['exit_code']);
    }

    public function testShouldProfileWhenDisabled()
    {
        config(['perfbase.enabled' => false]);
        $this->assertFalse($this->callPrivateMethod('shouldProfile'));
    }

    public function testShouldProfileWhenEnabled()
    {
        config(['perfbase.enabled' => true]);
        config(['perfbase.include.console' => ['test:command']]);
        config(['perfbase.exclude.console' => []]);

        $this->assertTrue($this->callPrivateMethod('shouldProfile'));
    }

    public function testGetCommandName()
    {
        $this->assertEquals('test:command', $this->callPrivateMethod('getCommandName'));
    }

    public function testGetVerbosityLevel()
    {
        $this->assertEquals('normal', $this->callPrivateMethod('getVerbosityLevel'));
    }

    public function testSetDefaultAttributes()
    {
        $this->callPrivateMethod('setDefaultAttributes');
        $attributes = $this->getPrivateProperty('attributes');

        $this->assertEquals('test:command', $attributes['action']);
        $this->assertArrayHasKey('arguments', $attributes);
        $this->assertArrayHasKey('options', $attributes);
        $this->assertEquals('normal', $attributes['verbosity']);
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
