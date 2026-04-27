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
            'perfbase.include.jobs' => [],
            'perfbase.exclude.jobs' => [],
        ]);

        $this->assertFalse(FilterMatcher::passesConfigFilters(['App\Jobs\SendEmail'], 'jobs'));
    }

    public function testFailsWhenIncludeDoesNotMatch(): void
    {
        config([
            'perfbase.include.artisan' => ['migrate*'],
            'perfbase.exclude.artisan' => [],
        ]);

        $this->assertFalse(FilterMatcher::passesConfigFilters(['queue:work'], 'artisan'));
    }

    public function testPassesWhenIncludeMatchesSpecificPattern(): void
    {
        config([
            'perfbase.include.jobs' => ['App\Jobs\Important*'],
            'perfbase.exclude.jobs' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['App\Jobs\ImportantEmail'], 'jobs'));
        $this->assertFalse(FilterMatcher::passesConfigFilters(['App\Jobs\TrivialCleanup'], 'jobs'));
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
            'perfbase.include.artisan' => ['migrate', 'db:seed', 'queue:*'],
            'perfbase.exclude.artisan' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['migrate'], 'artisan'));
        $this->assertTrue(FilterMatcher::passesConfigFilters(['db:seed'], 'artisan'));
        $this->assertTrue(FilterMatcher::passesConfigFilters(['queue:work'], 'artisan'));
        $this->assertFalse(FilterMatcher::passesConfigFilters(['horizon:work'], 'artisan'));
    }

    public function testEmptyExcludeDoesNotBlock(): void
    {
        config([
            'perfbase.include.jobs' => ['*'],
            'perfbase.exclude.jobs' => [],
        ]);

        $this->assertTrue(FilterMatcher::passesConfigFilters(['App\Jobs\AnyJob'], 'jobs'));
    }
}
