# Perfbase for Laravel

[![Packagist License](https://img.shields.io/packagist/l/perfbase/laravel)](https://github.com/perfbaseorg/laravel/blob/main/LICENSE.txt)
[![Packagist Version](https://img.shields.io/packagist/v/perfbase/laravel)](https://packagist.org/packages/perfbase/laravel)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/perfbaseorg/laravel/ci.yml?branch=main)](https://github.com/perfbaseorg/laravel/actions/workflows/ci.yml)

Seamless Laravel integration for Perfbase - a comprehensive Application Performance Monitoring (APM) solution that provides real-time insights into your Laravel application's performance, database queries, HTTP requests, queue jobs, and more.

## Features

- 🚀 **Automatic Profiling** - HTTP requests, console commands, and queue jobs
- 📊 **Multi-span Tracing** - Track nested operations within requests
- 🔍 **Database Query Monitoring** - Monitor all database operations with timing
- 🌐 **HTTP Request Tracking** - Monitor outbound API calls and their performance  
- ⚡ **Queue Job Profiling** - Track background job performance and failures
- 🏷️ **Custom Attributes** - Add contextual metadata to traces
- 🎯 **Smart Sampling** - Control data collection with configurable sample rates
- 💾 **Flexible Data Storage** - Sync immediately or buffer locally (file/database)
- 🔧 **Granular Control** - Include/exclude specific routes, commands, or jobs
- 🛡️ **Multi-tenant Support** - Organization and project-level data isolation

## Requirements

- **PHP**: 7.4 to 8.4
- **Laravel**: 8.0, 9.0, 10.0, 11.0, or 12.0
- **Extensions**: 
  - `ext-json` (usually enabled by default)
  - `ext-zlib` (usually enabled by default)
  - `ext-perfbase` (Perfbase PHP extension)
- **Dependencies**: Guzzle HTTP 7.0+

## Installation

### 1. Install the Package

```bash
composer require perfbase/laravel
```

### 2. Install the Perfbase PHP Extension

The `ext-perfbase` PHP extension is required. Install it using:

```bash
bash -c "$(curl -fsSL https://cdn.perfbase.com/install.sh)"
```

**Important**: Restart your web server after installation.

### 3. Publish Configuration

```bash
php artisan vendor:publish --tag="perfbase-config"
```

This creates `config/perfbase.php` with all available options.

### 4. Configure Environment

Add to your `.env` file:

```env
PERFBASE_ENABLED=true
PERFBASE_API_KEY=your_api_key_here
PERFBASE_SAMPLE_RATE=0.1
PERFBASE_SENDING_MODE=sync
```

### 5. Add Middleware (Optional but Recommended)

For HTTP request profiling, add the middleware to your HTTP kernel:

```php
// app/Http/Kernel.php
protected $middleware = [
    // ... other middleware
    \Perfbase\Laravel\Middleware\PerfbaseMiddleware::class,
];
```

Or apply to specific route groups:

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        // ... other middleware
        \Perfbase\Laravel\Middleware\PerfbaseMiddleware::class,
    ],
];
```

## Configuration

### Basic Configuration

The package auto-registers and provides several configuration options:

```php
// config/perfbase.php
return [
    'enabled' => env('PERFBASE_ENABLED', false),
    'api_key' => env('PERFBASE_API_KEY'),
    'sample_rate' => env('PERFBASE_SAMPLE_RATE', 0.1),
    
    'sending' => [
        'mode' => env('PERFBASE_SENDING_MODE', 'sync'),
        'timeout' => env('PERFBASE_TIMEOUT', 5),
        'proxy' => env('PERFBASE_PROXY'),
    ],
    
    'flags' => env('PERFBASE_FLAGS', \Perfbase\SDK\FeatureFlags::DefaultFlags),
    // ... more options
];
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PERFBASE_ENABLED` | `false` | Enable/disable profiling |
| `PERFBASE_API_KEY` | `null` | Your Perfbase API key (required) |
| `PERFBASE_SAMPLE_RATE` | `0.1` | Sampling rate (0.0 to 1.0) |
| `PERFBASE_SENDING_MODE` | `sync` | Data sending mode (`sync`, `file`, `database`) |
| `PERFBASE_TIMEOUT` | `5` | API request timeout in seconds |
| `PERFBASE_PROXY` | `null` | HTTP proxy URL |
| `PERFBASE_FLAGS` | Default flags | Profiling feature flags |

### Sending Modes

#### Sync Mode (Default)
Data is sent immediately to Perfbase:
```env
PERFBASE_SENDING_MODE=sync
```

#### File Buffering
Data is stored in local files and sent later:
```env
PERFBASE_SENDING_MODE=file
```

#### Database Buffering  
Data is cached in your database and sent later:
```env
PERFBASE_SENDING_MODE=database
```

### Profiling Features Control

Control which profiling features are enabled:

```php
use Perfbase\SDK\FeatureFlags;

// In config/perfbase.php
'flags' => FeatureFlags::DefaultFlags, // Recommended for most apps
'flags' => FeatureFlags::AllFlags,     // All available features
'flags' => FeatureFlags::TrackCpuTime | FeatureFlags::TrackPdo, // Custom combination
```

Available flags:
- `UseCoarseClock` - Faster timing (reduced overhead)
- `TrackCpuTime` - Monitor CPU time usage
- `TrackMemoryAllocation` - Track memory allocation patterns
- `TrackPdo` - Monitor database queries
- `TrackHttp` - Track outbound HTTP requests
- `TrackCaches` - Monitor cache operations
- `TrackMongodb` - Track MongoDB operations
- `TrackElasticsearch` - Monitor Elasticsearch queries
- `TrackQueues` - Track queue/background jobs
- `TrackAwsSdk` - Monitor AWS SDK operations
- `TrackFileOperations` - Track file I/O operations

### Include/Exclude Filters

Control which routes, commands, and jobs are profiled:

```php
// config/perfbase.php
'include' => [
    'http' => [
        'api/*',
        'admin/*'
    ],
    'console' => [
        'app:*',
        'queue:*'
    ],
    'queue' => [
        'App\\Jobs\\*'
    ]
],

'exclude' => [
    'http' => [
        'health-check',
        '_debugbar/*'
    ],
    'console' => [
        'horizon:*',
        'telescope:*'
    ],
    'queue' => [
        'App\\Jobs\\DebugJob'
    ]
]
```

## Usage

### Automatic Profiling

Once configured, Perfbase automatically profiles:

- **HTTP Requests** (when middleware is added)
- **Console Commands** (all artisan commands)
- **Queue Jobs** (all queued jobs)

### Manual Profiling

Use the facade for custom profiling:

```php
use Perfbase\Laravel\Facades\Perfbase;

// Start a custom span
Perfbase::startTraceSpan('custom-operation', [
    'operation_type' => 'data_processing',
    'record_count' => '1000'
]);

// Add attributes during execution
Perfbase::setAttribute('processing_method', 'batch');
Perfbase::setAttribute('memory_usage', memory_get_usage());

try {
    // Your custom logic here
    processLargeDataset();
    
    Perfbase::setAttribute('status', 'success');
} catch (Exception $e) {
    Perfbase::setAttribute('status', 'error');
    Perfbase::setAttribute('error_message', $e->getMessage());
} finally {
    // Always stop the span
    Perfbase::stopTraceSpan('custom-operation');
}

// Submit the trace data
Perfbase::submitTrace();
```

### Service Injection

Use dependency injection in your services:

```php
use Perfbase\SDK\Perfbase;

class DataProcessingService
{
    public function __construct(private Perfbase $perfbase)
    {
    }
    
    public function processData(array $data): array
    {
        $this->perfbase->startTraceSpan('data-processing', [
            'record_count' => count($data),
            'data_type' => 'user_records'
        ]);
        
        $result = $this->performProcessing($data);
        
        $this->perfbase->setAttribute('processed_count', count($result));
        $this->perfbase->stopTraceSpan('data-processing');
        
        return $result;
    }
}
```

### User-Specific Profiling

Profile specific users by implementing the `ProfiledUser` interface:

```php
use Perfbase\Laravel\Interfaces\ProfiledUser;

class User extends Authenticatable implements ProfiledUser
{
    public function shouldBeProfiled(): bool
    {
        // Profile admin users or users in beta testing
        return $this->isAdmin() || $this->isBetaTester();
    }
}
```

## Artisan Commands

### Sync Buffered Data

When using `file` or `database` sending modes, use this command to send buffered data:

```bash
# Send all buffered trace data to Perfbase
php artisan perfbase:sync

# Recommended: Set up a cron job
# * * * * * cd /path-to-your-project && php artisan perfbase:sync >> /dev/null 2>&1
```

### Clear Buffered Data

Remove all locally buffered traces:

```bash
# Clear all buffered data (useful for debugging)
php artisan perfbase:clear
```

## Advanced Configuration

### Database Strategy Setup

When using `database` sending mode, you may need to run the migration:

```bash
php artisan migrate
```

The package includes a migration for the `perfbase_profiles` table.

### Custom Cache Paths

For file-based buffering, customize the storage path:

```php
// config/perfbase.php
'sending' => [
    'mode' => 'file',
    'config' => [
        'file' => [
            'path' => storage_path('app/perfbase-cache'),
        ],
    ],
],
```

### Performance Optimization

For high-traffic applications:

```php
// config/perfbase.php
'sample_rate' => 0.01, // Profile 1% of requests
'flags' => \Perfbase\SDK\FeatureFlags::UseCoarseClock | 
           \Perfbase\SDK\FeatureFlags::TrackCpuTime |
           \Perfbase\SDK\FeatureFlags::TrackPdo,
'sending' => ['mode' => 'file'], // Buffer locally
```

### Multi-Environment Setup

```php
// config/perfbase.php
'enabled' => env('PERFBASE_ENABLED', app()->environment('production')),
'sample_rate' => env('PERFBASE_SAMPLE_RATE', match(app()->environment()) {
    'production' => 0.1,
    'staging' => 0.5,
    'local' => 1.0,
    default => 0.1
}),
```

## Facade Methods

The Perfbase facade provides access to all SDK methods:

| Method | Description |
|--------|-------------|
| `startTraceSpan($name, $attributes = [])` | Start profiling a named span |
| `stopTraceSpan($name)` | Stop profiling a named span |
| `setAttribute($key, $value)` | Add attribute to current trace |
| `setFlags($flags)` | Change profiling feature flags |
| `submitTrace()` | Submit trace data to Perfbase |
| `getTraceData($spanName = '')` | Get raw trace data |
| `reset()` | Clear current trace session |
| `isExtensionAvailable()` | Check if extension is loaded |

## Error Handling

The package handles errors gracefully:

```php
// The package won't break your app if Perfbase is unavailable
try {
    Perfbase::startTraceSpan('critical-operation');
    // Your code here
} catch (\Perfbase\SDK\Exception\PerfbaseExtensionException $e) {
    // Extension not available - log but continue
    Log::warning('Perfbase extension not available: ' . $e->getMessage());
}
```

## Troubleshooting

### Extension Not Found

```bash
# Check if extension is loaded
php -m | grep perfbase

# Check PHP configuration
php --ini

# Reinstall extension
bash -c "$(curl -fsSL https://cdn.perfbase.com/install.sh)"
```

### Permission Issues (File Mode)

```bash
# Ensure storage directory is writable
chmod -R 755 storage/perfbase
chown -R www-data:www-data storage/perfbase
```

### High Memory Usage

```php
// Reduce profiling overhead
'flags' => \Perfbase\SDK\FeatureFlags::UseCoarseClock | 
           \Perfbase\SDK\FeatureFlags::TrackCpuTime,
'sample_rate' => 0.01, // Lower sample rate
```

### Database Issues (Database Mode)

```bash
# Ensure migration is run
php artisan migrate

# Check database connection
php artisan tinker
>>> DB::connection()->getPdo();
```

## Testing

When testing your Laravel application:

```php
// Disable Perfbase in tests
// phpunit.xml
<env name="PERFBASE_ENABLED" value="false"/>

// Or mock the facade in tests
public function test_something()
{
    Perfbase::shouldReceive('startTraceSpan')->once();
    Perfbase::shouldReceive('stopTraceSpan')->once();
    
    // Your test code
}
```

## Performance Impact

- **Minimal Overhead**: ~1-3ms per request with default settings
- **Sampling**: Use sample rates to reduce impact in production
- **Async Options**: File/database modes reduce request impact
- **Selective Profiling**: Use include/exclude filters strategically

## Security Considerations

- **API Key Security**: Store API keys in environment variables, not code
- **Data Privacy**: Configure include/exclude filters to avoid sensitive routes
- **User Profiling**: Implement `ProfiledUser` interface to control user-specific profiling
- **Network Security**: Use HTTPS endpoints and configure proxy if needed

## Examples

### E-commerce Checkout

```php
class CheckoutController extends Controller
{
    public function process(Request $request)
    {
        Perfbase::startTraceSpan('checkout-process', [
            'user_id' => auth()->id(),
            'cart_items' => $request->items->count(),
            'payment_method' => $request->payment_method
        ]);
        
        try {
            $order = $this->createOrder($request);
            $payment = $this->processPayment($order);
            
            Perfbase::setAttribute('order_id', $order->id);
            Perfbase::setAttribute('payment_status', $payment->status);
            
            return response()->json(['order' => $order]);
        } finally {
            Perfbase::stopTraceSpan('checkout-process');
        }
    }
}
```

### Background Job Processing

```php
class ProcessEmailCampaignJob implements ShouldQueue
{
    public function handle()
    {
        // Automatic profiling happens via queue listener
        // But you can add custom spans for detailed tracking
        
        Perfbase::startTraceSpan('email-template-render');
        $template = $this->renderTemplate();
        Perfbase::stopTraceSpan('email-template-render');
        
        Perfbase::startTraceSpan('email-send-batch');
        $this->sendEmails($template);
        Perfbase::stopTraceSpan('email-send-batch');
    }
}
```

## Documentation

Comprehensive documentation is available at [https://docs.perfbase.com](https://docs.perfbase.com), including:

- Complete API reference
- Framework-specific guides
- Performance optimization tips
- Data privacy and security policies
- Troubleshooting guides

## Contributing

We welcome contributions! Please see our [contributing guidelines](CONTRIBUTING.md) and feel free to submit pull requests.

## Security

If you discover any security-related issues, please email [security@perfbase.com](mailto:security@perfbase.com) instead of using the issue tracker.

## Support

- **Email**: [support@perfbase.com](mailto:support@perfbase.com)
- **Documentation**: [https://docs.perfbase.com](https://docs.perfbase.com)
- **Issues**: [GitHub Issues](https://github.com/perfbaseorg/laravel/issues)

## License

This project is licensed under the Apache License 2.0. Please see the [License File](LICENSE.txt) for more information.

---

**Made with ❤️ by the Perfbase team**