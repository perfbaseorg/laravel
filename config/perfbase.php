<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | This is for your Perfbase API key assigned to your project.
    | You can find your API key in the project settings on the Perfbase console.
    |
    */
    'api_key' => env('PERFBASE_API_KEY'),


    'cache' => [
        'enabled' => false, // false, 'file' or 'database'
        'config' => [
            'file' => [
                'path' => storage_path('perfbase'),
            ],
            'database' => [
                'connection' => 'default',
                'table' => 'perfbase_cache',
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Profiling
    |--------------------------------------------------------------------------
    |
    | This option allows you to enable or disable the profiler. When disabled,
    | the profiler will not collect any data. This is useful when you want to
    | disable the profiler in production or specific environments.
    |
    */
    'enabled' => env('PERFBASE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Profiler Features
    |--------------------------------------------------------------------------
    |
    | This config allows you to control different features within the profiler.
    | You can enable or disable specific features based on your requirements.
    |
    | Note: Some features will cause a performance overhead. Use with caution.
    | The README.md contains all the details about each feature.
    |
    */
    'profiler_features' => [
        'ignored_functions' => env('PERFBASE_IGNORED_FUNCTIONS', []),
        'use_coarse_clock' => env('PERFBASE_USE_COARSE_CLOCK', false),
        'track_file_compilation' => env('PERFBASE_TRACK_FILE_COMPILATION', true),
        'track_memory_allocation' => env('PERFBASE_TRACK_MEMORY_ALLOCATION', false),
        'track_cpu_time' => env('PERFBASE_TRACK_CPU_TIME', true),
        'track_pdo' => env('PERFBASE_TRACK_PDO', true),
        'track_http' => env('PERFBASE_TRACK_HTTP', true),
        'track_caches' => env('PERFBASE_TRACK_CACHES', true),
        'track_mongodb' => env('PERFBASE_TRACK_MONGODB', true),
        'track_elasticsearch' => env('PERFBASE_TRACK_ELASTICSEARCH', true),
        'track_queues' => env('PERFBASE_TRACK_QUEUES', true),
        'track_aws_sdk' => env('PERFBASE_TRACK_AWS_SDK', true),
        'track_file_operations' => env('PERFBASE_TRACK_FILE_OPERATIONS', true),
        'proxy' => env('PERFBASE_PROXY', null),
        'timeout' => env('PERFBASE_TIMEOUT', 10),
        'async_delivery' => env('PERFBASE_ASYNC', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Perfbase Profiling - Include List
    |--------------------------------------------------------------------------
    |
    | This configuration determines which actions are included for profiling
    | when Perfbase profiling is enabled. Specify actions using exact matches,
    | wildcards, namespaces, controller names, methods, or regular expressions.
    |
    | Matching Rules:
    | - **Disable Profiling:** Leave the list empty ([]).
    | - **Match All Actions:** Use ['.*'] or ['*'].
    | - **HTTP Verb & URI:**
    |     - Exact Match: 'GET /'
    |     - URI Match: '/api/example'
    |     - Wildcard Match: 'POST /api/*'
    | - **Namespace & Controllers:**
    |     - Namespace Prefix: 'App\Http\Controllers\.*'
    |     - Specific Controller: 'UserController'
    | - **Methods:**
    |     - Specific Method: 'getUsers'
    | - **Controller-Method Combination:**
    |     - 'App\Http\Controllers\UserController@getUsers'
    | - **Regular Expressions:**
    |     - '/^App\\Http\\Controllers\\.*$/'
    |     - '/^POST \/users\/([0-9]+)\//'
    |
    | Examples:
    | - Include all actions:
    |     ['.*']
    |
    | - Profile a root GET request:
    |     ['GET /']
    |
    | - Profile all POST requests under `/api/`:
    |     ['POST /api/*']
    |
    | - Profile all controllers in a namespace:
    |     ['App\Http\Controllers\.*']
    |
    | - Profile a specific controller:
    |     ['UserController']
    |
    | - Profile a specific method:
    |     ['getUsers']
    |
    | - Profile a specific controller and method:
    |     ['App\Http\Controllers\UserController@getUsers']
    |
    | - Combine multiple rules:
    |     [
    |         'GET /',
    |         'POST /api/*',
    |         'App\Http\Controllers\UserController@getUsers'
    |     ]
    |
    | - Use regex for complex patterns:
    |     ['^App\\Http\\Controllers\\.*$']
    |
    */
    'include' => [
        'http' => ['.*'],
        'artisan' => ['.*'],
        'jobs' => ['.*'],
        'schedule' => ['.*'],
        'exception' => ['.*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exclude List
    |--------------------------------------------------------------------------
    |
    | If profiling is enabled, this option controls which actions are excluded.
    | See above `include` section for notes on supported values.
    |
    */
    'exclude' => [
        'http' => [],
        'artisan' => ['queue:work'],
        'jobs' => [],
        'schedule' => [],
        'exception' => [],
    ],

];
