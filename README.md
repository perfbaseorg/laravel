<p align="center">
  <a href="https://perfbase.com">
    <img src="https://cdn.perfbase.com/img/logo-full.svg" alt="Perfbase" width="300">
  </a>
</p>

<h3 align="center">Perfbase for Laravel</h3>
<p align="center">
  Laravel integration for <a href="https://perfbase.com">Perfbase</a>.
</p>

<p align="center">
  <a href="https://packagist.org/packages/perfbase/laravel"><img src="https://img.shields.io/packagist/v/perfbase/laravel" alt="Packagist Version"></a>
  <a href="https://github.com/perfbaseorg/laravel/blob/main/LICENSE.txt"><img src="https://img.shields.io/packagist/l/perfbase/laravel" alt="License"></a>
  <a href="https://github.com/perfbaseorg/laravel/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/perfbaseorg/laravel/ci.yml?branch=main" alt="CI"></a>
  <img src="https://img.shields.io/badge/php-7.4%2B-blue" alt="PHP Version">
  <img src="https://img.shields.io/badge/laravel-8.x--13.x-blue" alt="Laravel Version">
</p>

This package is a thin adapter over [`perfbase/php-sdk`](https://packagist.org/packages/perfbase/php-sdk). It wires Laravel request, console, and queue lifecycles into the SDK and leaves trace transport, submission, and extension handling to the shared SDK.

## What it profiles

- HTTP requests when the Perfbase middleware is installed
- Artisan commands through Laravel console events
- Queue jobs through Laravel queue events
- Manual custom spans through the `Perfbase` facade or injected SDK client

## Requirements

- PHP `7.4` to `8.5`
- Laravel `8.x`, `9.x`, `10.x`, `11.x`, `12.x`, or `13.x`
- `ext-json`
- `ext-zlib`
- `ext-perfbase`

## Installation

Install the package from Packagist:

```bash
composer require perfbase/laravel:^1.0
```

Install the native Perfbase extension if it is not already available:

```bash
bash -c "$(curl -fsSL https://cdn.perfbase.com/install.sh)"
```

Restart PHP-FPM, Octane workers, Horizon workers, or your web server after installing the extension.

Publish the config file:

```bash
php artisan vendor:publish --tag="perfbase-config"
```

Add the minimum environment variables:

```env
PERFBASE_ENABLED=true
PERFBASE_API_KEY=your_api_key_here
PERFBASE_SAMPLE_RATE=0.1
```

### HTTP middleware

HTTP profiling is enabled only when the middleware is present.

For Laravel 8 to 10, add it to `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ...
    \Perfbase\Laravel\Middleware\PerfbaseMiddleware::class,
];
```

Or attach it to a middleware group:

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \Perfbase\Laravel\Middleware\PerfbaseMiddleware::class,
    ],
];
```

For Laravel 11+, register it in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Perfbase\Laravel\Middleware\PerfbaseMiddleware;

return Application::configure(dirname(__DIR__))
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(PerfbaseMiddleware::class);
    })
    ->create();
```

Console and queue profiling do not need middleware. They are wired through the package service provider.

## Configuration

Published config lives at `config/perfbase.php`.

```php
return [
    'enabled' => env('PERFBASE_ENABLED', false),
    'debug' => env('PERFBASE_DEBUG', false),
    'log_errors' => env('PERFBASE_LOG_ERRORS', true),
    'api_key' => env('PERFBASE_API_KEY'),
    'sample_rate' => env('PERFBASE_SAMPLE_RATE', 0.1),
    'timeout' => env('PERFBASE_TIMEOUT', 5),
    'proxy' => env('PERFBASE_PROXY'),
    'flags' => env('PERFBASE_FLAGS', \Perfbase\SDK\FeatureFlags::DefaultFlags),
    'include' => [
        'http' => ['.*'],
        'console' => ['.*'],
        'queue' => ['.*'],
    ],
    'exclude' => [
        'http' => [],
        'console' => ['queue:work'],
        'queue' => [],
    ],
];
```

### Environment variables

| Variable | Default | Purpose |
| --- | --- | --- |
| `PERFBASE_ENABLED` | `false` | Global on/off switch |
| `PERFBASE_API_KEY` | `null` | Perfbase API key |
| `PERFBASE_SAMPLE_RATE` | `0.1` | Sampling rate from `0.0` to `1.0` |
| `PERFBASE_DEBUG` | `false` | Re-throw profiling exceptions |
| `PERFBASE_LOG_ERRORS` | `true` | Log profiling failures when debug is off |
| `PERFBASE_TIMEOUT` | `5` | Trace submission timeout in seconds |
| `PERFBASE_PROXY` | `null` | Optional outbound proxy |
| `PERFBASE_FLAGS` | `FeatureFlags::DefaultFlags` | Perfbase extension feature flags |

### Feature flags

```php
use Perfbase\SDK\FeatureFlags;

'flags' => FeatureFlags::DefaultFlags;
'flags' => FeatureFlags::AllFlags;
'flags' => FeatureFlags::TrackCpuTime | FeatureFlags::TrackPdo;
```

Common flags:

- `UseCoarseClock`
- `TrackCpuTime`
- `TrackMemoryAllocation`
- `TrackPdo`
- `TrackHttp`
- `TrackCaches`
- `TrackMongodb`
- `TrackElasticsearch`
- `TrackQueues`
- `TrackAwsSdk`
- `TrackFileOperations`
- `TrackFileCompilation`
- `TrackFileDefinitions`
- `TrackExceptions`

### Include and exclude filters

Filters are split by context: `http`, `console`, and `queue`.

```php
'include' => [
    'http' => ['GET /api/*', 'POST /checkout'],
    'console' => ['migrate*', 'app:*'],
    'queue' => ['App\\Jobs\\Important*'],
],

'exclude' => [
    'http' => ['GET /health*', '_debugbar/*'],
    'console' => ['queue:work', 'horizon:*'],
    'queue' => ['App\\Jobs\\NoisyDebugJob'],
],
```

Supported filter styles:

- Wildcards like `GET /api/*`
- Regex patterns like `/^POST \/checkout/`
- Command patterns like `queue:*`
- Job class patterns like `App\\Jobs\\*`
- Controller or action strings matched through Laravel's string matcher

## How it behaves

### HTTP requests

`PerfbaseMiddleware` creates an `HttpTraceLifecycle` for the current request.

Recorded attributes include:

- `source=http`
- `action`
- `http_method`
- `http_url`
- `http_status_code`
- `user_ip`
- `user_agent`
- `user_id` when available
- `environment`
- `app_version`
- `hostname`
- `php_version`

### Console commands

The service provider listens to Laravel console events and creates a `ConsoleTraceLifecycle`.

Recorded attributes include:

- `source=console`
- `action`
- `exit_code`
- `exception` when present
- `environment`
- `app_version`
- `hostname`
- `php_version`

### Queue jobs

The service provider listens to queue worker events and creates a `QueueTraceLifecycle`.

Recorded attributes include:

- `source=queue`
- `action`
- `queue`
- `connection`
- `exception` when present
- `environment`
- `app_version`
- `hostname`
- `php_version`

## Manual spans

Use the facade when you want custom spans inside your own application code:

```php
use Perfbase\Laravel\Facades\Perfbase;

Perfbase::startTraceSpan('custom-operation', [
    'operation_type' => 'data_processing',
    'record_count' => '1000',
]);

Perfbase::setAttribute('processing_method', 'batch');
Perfbase::setAttribute('memory_usage', (string) memory_get_usage());

try {
    processLargeDataset();
    Perfbase::setAttribute('status', 'success');
} catch (\Exception $e) {
    Perfbase::setAttribute('status', 'error');
    Perfbase::setAttribute('error_message', $e->getMessage());
    throw $e;
} finally {
    Perfbase::stopTraceSpan('custom-operation');
}

$result = Perfbase::submitTrace();

if (!$result->isSuccess()) {
    logger()->warning('Perfbase trace submission failed', [
        'status' => $result->getStatus(),
        'message' => $result->getMessage(),
        'status_code' => $result->getStatusCode(),
    ]);
}
```

Note that Perfbase trace attributes are string values. Cast integers and booleans before passing them to `setAttribute()`.

## Dependency injection

You can inject the SDK client directly:

```php
use Perfbase\SDK\Perfbase;

class DataProcessingService
{
    /** @var Perfbase */
    private $perfbase;

    public function __construct(Perfbase $perfbase)
    {
        $this->perfbase = $perfbase;
    }

    public function processData(array $data): array
    {
        $this->perfbase->startTraceSpan('data-processing', [
            'record_count' => (string) count($data),
            'data_type' => 'user_records',
        ]);

        try {
            $result = $this->performProcessing($data);
            $this->perfbase->setAttribute('processed_count', (string) count($result));
            return $result;
        } finally {
            $this->perfbase->stopTraceSpan('data-processing');
        }
    }
}
```

## User-specific request profiling

If your authenticated user model implements `Perfbase\Laravel\Interfaces\ProfiledUser`, HTTP request profiling will respect `shouldBeProfiled()`.

```php
use Perfbase\Laravel\Interfaces\ProfiledUser;

class User extends Authenticatable implements ProfiledUser
{
    public function shouldBeProfiled(): bool
    {
        return $this->isAdmin() || $this->isBetaTester();
    }
}
```

If the authenticated user does not implement `ProfiledUser`, the package falls back to normal request filtering rules.

## Facade methods

| Method | Description |
| --- | --- |
| `startTraceSpan($name, $attributes = [])` | Start a named span |
| `stopTraceSpan($name)` | Stop a named span |
| `setAttribute($key, $value)` | Add a string attribute to the current trace |
| `setFlags($flags)` | Change extension feature flags |
| `submitTrace()` | Submit trace data and return a `SubmitResult` |
| `getTraceData($spanName = '')` | Get raw trace data |
| `reset()` | Clear the current trace session |
| `isExtensionAvailable()` | Check whether the native extension is loaded |

## Error handling

The package is designed to fail open in normal operation. When profiling cannot start or trace submission fails, your Laravel request, command, or job should continue running.

Use `PERFBASE_DEBUG=true` if you want profiling exceptions to surface during local development.

## Testing

In application tests, it is often simplest to disable profiling:

```xml
<env name="PERFBASE_ENABLED" value="false"/>
```

You can also mock the facade:

```php
use Perfbase\Laravel\Facades\Perfbase;

public function test_something()
{
    Perfbase::shouldReceive('startTraceSpan')->once();
    Perfbase::shouldReceive('stopTraceSpan')->once();

    // ...
}
```

## Troubleshooting

### Extension not loaded

```bash
php -m | grep perfbase
php --ini
bash -c "$(curl -fsSL https://cdn.perfbase.com/install.sh)"
```

### High overhead

- Lower `PERFBASE_SAMPLE_RATE`
- Use `FeatureFlags::UseCoarseClock`
- Disable feature flags you do not need
- Narrow your `include` filters and expand your `exclude` filters

## Documentation

Full documentation is available at [perfbase.com/docs](https://perfbase.com/docs).

- **Docs**: [perfbase.com/docs](https://perfbase.com/docs)
- **Issues**: [github.com/perfbaseorg/laravel/issues](https://github.com/perfbaseorg/laravel/issues)
- **Support**: [support@perfbase.com](mailto:support@perfbase.com)

## License

Apache-2.0. See [LICENSE.txt](LICENSE.txt).
