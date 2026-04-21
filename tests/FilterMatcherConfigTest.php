<?php

namespace Tests;

use Orchestra\Testbench\TestCase;
use Perfbase\Laravel\PerfbaseServiceProvider;
use Perfbase\Laravel\Support\FilterMatcher;

class FilterMatcherConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PerfbaseServiceProvider::class];
    }

    public function testPassesWhenIncludeMatchesAll(): void
    {
        config([
            'perfbase.include.http' => ['*'],
            'perfbase.exclude.http' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['GET /api/users'], 'http'));
    }

    public function testFailsWhenNoIncludes(): void
    {
        config([
            'perfbase.include.queue' => [],
            'perfbase.exclude.queue' => [],
        ]);

        $this->assertFalse(FilterMatcher::passesConfigFilters(['App\Jobs\SendEmail'], 'queue'));
    }

    public function testFailsWhenIncludeDoesNotMatch(): void
    {
        config([
            'perfbase.include.console' => ['migrate*'],
            'perfbase.exclude.console' => [],
        ]);

        $this->assertFalse(FilterMatcher::passesConfigFilters(['queue:work'], 'console'));
    }

    public function testPassesWhenIncludeMatchesSpecificPattern(): void
    {
        config([
            'perfbase.include.queue' => ['App\Jobs\Important*'],
            'perfbase.exclude.queue' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['App\Jobs\ImportantEmail'], 'queue'));
        $this->assertFalse(FilterMatcher::passesConfigFilters(['App\Jobs\TrivialCleanup'], 'queue'));
    }

    public function testExcludeOverridesInclude(): void
    {
        config([
            'perfbase.include.http' => ['*'],
            'perfbase.exclude.http' => ['GET /health*'],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['POST /api/users'], 'http'));
        $this->assertFalse(FilterMatcher::passesConfigFilters(['GET /health-check'], 'http'));
    }

    public function testHandlesNullIncludeConfig(): void
    {
        config(['perfbase.include.http' => null]);

        $this->assertFalse(FilterMatcher::passesConfigFilters(['GET /'], 'http'));
    }

    public function testHandlesMissingConfigKey(): void
    {
        // Config key that doesn't exist at all
        $this->assertFalse(FilterMatcher::passesConfigFilters(['something'], 'nonexistent'));
    }

    public function testRegexPatternInConfig(): void
    {
        config([
            'perfbase.include.http' => ['/^POST \/api\/.*/'],
            'perfbase.exclude.http' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['POST /api/users'], 'http'));
        $this->assertFalse(FilterMatcher::passesConfigFilters(['GET /api/users'], 'http'));
    }

    public function testRouteNamePatternsWorkThroughExistingHttpMatcher(): void
    {
        config([
            'perfbase.include.http' => ['admin.users.*'],
            'perfbase.exclude.http' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(
            ['GET /admin/users', 'admin.users.index'],
            'http'
        ));
        $this->assertFalse(FilterMatcher::passesConfigFilters(
            ['GET /admin/users', 'admin.roles.index'],
            'http'
        ));
    }

    public function testMultipleIncludePatterns(): void
    {
        config([
            'perfbase.include.console' => ['migrate', 'db:seed', 'queue:*'],
            'perfbase.exclude.console' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['migrate'], 'console'));
        $this->assertTrue(FilterMatcher::passesConfigFilters(['db:seed'], 'console'));
        $this->assertTrue(FilterMatcher::passesConfigFilters(['queue:work'], 'console'));
        $this->assertFalse(FilterMatcher::passesConfigFilters(['horizon:work'], 'console'));
    }

    public function testEmptyExcludeDoesNotBlock(): void
    {
        config([
            'perfbase.include.queue' => ['*'],
            'perfbase.exclude.queue' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['App\Jobs\AnyJob'], 'queue'));
    }
}
