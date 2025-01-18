# Perfbase for Laravel

[![Packagist License](https://img.shields.io/packagist/l/perfbase/laravel)](https://github.com/perfbaseorg/laravel/blob/main/LICENSE.txt)
[![Packagist Version](https://img.shields.io/packagist/v/perfbase/laravel)](https://packagist.org/packages/perfbase/laravel)
[![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/perfbaseorg/laravel/ci.yml?branch=main)](https://github.com/perfbaseorg/laravel/actions/workflows/ci.yml)

Laravel integration for Perfbase - the PHP profiling service that helps you understand and optimize your application's performance.

## Installation

1. Install package to your laravel Project
```bash
composer require perfbase/laravel
```

2. Publish configuration
```bash
php artisan vendor:publish --tag="perfbase-config"
```

3. Add basic Perfbase config to your `.env` file
```env
PERFBASE_ENABLED=true
PERFBASE_API_KEY=your_key_here
PERFBASE_SAMPLE_RATE=1.0
```

4. Add our to your middleware stack
```php
\Perfbase\Laravel\Middleware\PerfbaseMiddleware::class
```

5. Start profiling your application!

### Sending mode - local buffering

If you'd like to buffer data before sending it to Perfbase, you can configure the `PERFBASE_SENDING_MODE` option. 
The available sending mode values are: 

1. `sync`: Sends data immediately without buffering.
2. `database`: Stores data in files before sending it to Perfbase.
3. `file`: Caches data in a database table before sending.

### Available Commands

```bash
# Send locally buffered traces to the API, then removes your locally buffered copy.
# Consider running this command in a scheduled cron job.
php artisan perfbase:sync

# Delete all locally buffered traces. (Useful for debugging, or destruction)
php artisan perfbase:clear
```

## Requirements

- PHP 7.4 or higher
- Laravel 8.0 or higher
- Perfbase PHP extension

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email security@perfbase.com instead of using the issue tracker.

## License

The Apache License Version 2.0. Please see [License File](LICENSE.txt) for more information.
