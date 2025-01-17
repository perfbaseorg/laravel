<?php

use Perfbase\Laravel\Middleware\PerfbaseMiddleware;
use PHPUnit\Framework\TestCase;

class MatchesFiltersTest extends TestCase
{
    public function testMatchesFilters()
    {
        $components = [
            'GET /',
            'GET /example',
            'POST /api/users',
            'App\Http\Controllers\UserController',
            'UserController',
        ];

        // Test cases
        $testCases = [
            [
                'filters' => ['GET /'],
                'expected' => true, // Exact match
            ],
            [
                'filters' => ['POST /api/*'],
                'expected' => true, // Matches with "*"
            ],
            [
                'filters' => ['App\Http\Controllers'],
                'expected' => true, // Namespace prefix match
            ],
            [
                'filters' => ['UserController'],
                'expected' => true, // Exact match for controller
            ],
            [
                'filters' => ['/^App\\\\Http\\\\Controllers\\\\.*$/'],
                'expected' => true, // Full regex match
            ],
            [
                'filters' => ['GET /invalid', 'POST /other'],
                'expected' => false, // No matches
            ],
            [
                'filters' => [],
                'expected' => false, // Empty filters should never match
            ],
            [
                'filters' => ['*'],
                'expected' => true, // Match all
            ],
            [
                'filters' => ['/^GET \/example\/([0-9]+)\/$/'],
                'expected' => false, // Assuming no component matches this regex
            ],
            [
                'filters' => ['GET /example/*'],
                'expected' => false, // Assuming no component starts with 'GET /example/'
            ],
            [
                'filters' => ['GET /example'],
                'expected' => true, // Exact match not present in components
            ],
            [
                'filters' => ['UserController', 'App\Http\Controllers'],
                'expected' => true, // Should match 'UserController' and 'App\Http\Controllers\UserController'
            ],
        ];

        // Run each test case
        foreach ($testCases as $case) {
            $result = PerfbaseMiddleware::matchesFilters($components, $case['filters']);
            $this->assertSame($case['expected'], $result, 'Failed matching filters: "' . implode('" and "', $case['filters']) . '" against "' . implode('", "', $components) . '"');
        }
    }
}