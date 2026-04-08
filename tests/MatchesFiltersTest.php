<?php

use Perfbase\Laravel\Support\FilterMatcher;
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

        $testCases = [
            [
                'filters' => ['GET /'],
                'expected' => true,
            ],
            [
                'filters' => ['POST /api/*'],
                'expected' => true,
            ],
            [
                'filters' => ['App\Http\Controllers*'],
                'expected' => true,
            ],
            [
                'filters' => ['UserController'],
                'expected' => true,
            ],
            [
                'filters' => ['/^App\\\\Http\\\\Controllers\\\\.*$/'],
                'expected' => true,
            ],
            [
                'filters' => ['GET /invalid', 'POST /other'],
                'expected' => false,
            ],
            [
                'filters' => [],
                'expected' => false,
            ],
            [
                'filters' => ['*'],
                'expected' => true,
            ],
            [
                'filters' => ['/^GET \/example\/([0-9]+)\/$/'],
                'expected' => false,
            ],
            [
                'filters' => ['GET /example/*'],
                'expected' => false,
            ],
            [
                'filters' => ['GET /example'],
                'expected' => true,
            ],
            [
                'filters' => ['UserController', 'App\Http\Controllers'],
                'expected' => true,
            ],
        ];

        foreach ($testCases as $case) {
            $result = FilterMatcher::matches($components, $case['filters']);
            $this->assertSame($case['expected'], $result, 'Failed matching filters: "' . implode('" and "', $case['filters']) . '" against "' . implode('", "', $components) . '"');
        }
    }
}
