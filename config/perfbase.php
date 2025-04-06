<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Profiling - Used to control the profiler state.
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
    | API Key - Required - Used to authenticate your project with Perfbase.
    |--------------------------------------------------------------------------
    |
    | This is for your Perfbase API key assigned to your project.
    | You can find your API key in the project settings on the Perfbase console.
    |
    */
    'api_key' => env('PERFBASE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Sample Rate - Required - Used to control the sampling rate of the profiler.
    |--------------------------------------------------------------------------
    |
    | The sample_rate setting determines the proportion of traces that will be captured
    | and sent to Perfbase. The value should be a decimal between 0.0 and 1.0.
    |
    | For example, a sample_rate of 0.1 will capture 10% of traces.
    | A sample_rate of 1.0 will capture all traces.
    | A sample_rate of 0.0 will capture no traces.
    |
    */
    'sample_rate' => env('PERFBASE_SAMPLE_RATE', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Sending Configuration - Used to control when data is sent to Perfbase.
    |--------------------------------------------------------------------------
    |
    | If you'd like to buffer data before sending it to Perfbase, you can configure
    | the `mode` option in the transmission settings. The available mode values are:
    | - 'sync': Sends data immediately without buffering.
    | - 'file': Stores data in files before sending it to Perfbase.
    | - 'database': Caches data in a database table before sending.
    |
    | When using modes other than 'sync', data will be collected locally.
    | To process and send this buffered data to Perfbase, use the `perfbase:sync`
    | Artisan command. It’s recommended to set up a cron to periodically run it.
    |
    */
    'sending' => [
        'mode' => env('PERFBASE_SENDING_MODE', 'sync'),
        'timeout' => env('PERFBASE_TIMEOUT', 10),
        'proxy' => env('PERFBASE_PROXY', null),
        'config' => [
            'sync' => [

            ],
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
    | Profiler Flags - Used to control the profiler features.
    |--------------------------------------------------------------------------
    |
    | This config allows you to control different features within the profiler.
    | You can enable or disable specific features based on your requirements.
    |
    | Note: Some features will cause a performance overhead. Use with caution.
    | The README.md contains all the details about each feature.
    |
    */
    'flags' => \Perfbase\SDK\FeatureFlags::DefaultFlags,

    /*
    |--------------------------------------------------------------------------
    | Route Include List - Used to control which routes are profiled.
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
    | Route Exclude List - Used to control which routes are not profiled.
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
