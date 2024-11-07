<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Perfbase API Key
    |--------------------------------------------------------------------------
    |
    | This is your authentication key for the Perfbase API
    |
    */
    'api_key' => env('PERFBASE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | API URL
    |--------------------------------------------------------------------------
    |
    | The base URL for the Perfbase API
    |
    */
    'api_url' => env('PERFBASE_API_URL', 'https://api.perfbase.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Enable Profiling
    |--------------------------------------------------------------------------
    |
    | This option controls whether Perfbase profiling is enabled
    |
    */
    'enabled' => env('PERFBASE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout for API requests in seconds
    |
    */
    'timeout' => env('PERFBASE_TIMEOUT', 1),

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how profiling data should be cached before sending to API
    | Supported: "none", "database", "file"
    |
    */
    'cache' => env('PERFBASE_CACHE', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Cache Connections
    |--------------------------------------------------------------------------
    |
    | Configuration for different caching strategies
    |
    */
    'connections' => [
        'database' => [
            'connection' => env('PERFBASE_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
            'table' => env('PERFBASE_TABLE_NAME', 'perfbase_profiles'),
        ],
        'file' => [
            'path' => storage_path('perfbase/profiles'),
            'extension' => '.profile.json',
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Schedule
    |--------------------------------------------------------------------------
    |
    | How often to sync cached profiles (in minutes)
    |
    */
    'sync_interval' => env('PERFBASE_SYNC_INTERVAL', 60),
];
