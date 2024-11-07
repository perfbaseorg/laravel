# Perfbase for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/perfbase/laravel.svg?style=flat-square)](https://packagist.org/packages/perfbase/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/perfbase/laravel.svg?style=flat-square)](https://packagist.org/packages/perfbase/laravel)

Laravel integration for Perfbase - the PHP profiling service that helps you understand and optimize your application's performance.

## Installation

You can install the package via composer:

```bash
composer require perfbase/laravel
```

After installation, publish the configuration file:

```bash
php artisan vendor:publish --tag="perfbase-config"
```

This will create a `perfbase.php` file in your `config` directory.

## Configuration

Add your Perfbase API key to your `.env` file:

```env
PERFBASE_API_KEY=your-api-key
PERFBASE_ENABLED=true
```

### Available Configuration Options

```
# Enable/disable profiling
PERFBASE_ENABLED=true

# Your API key
PERFBASE_API_KEY=your-api-key

API URL (defaults to https://api.perfbase.com/v1)
PERFBASE_API_URL=https://api.perfbase.com/v1

# Cache strategy: none, database, or file
PERFBASE_CACHE=none

# For database caching:
PERFBASE_DB_CONNECTION=mysql
PERFBASE_TABLE_NAME=perfbase_profiles

# How often to sync cached profiles (in minutes)
PERFBASE_SYNC_INTERVAL=60
```

## Usage

### Basic Usage

Perfbase will automatically profile your Laravel application's requests when enabled. No additional code is required.

### Manual Profiling

You can manually control profiling using the facade:

```php
use Perfbase\Laravel\Facades\Perfbase;
// Start profiling
Perfbase::startProfiling();
// Your code here...
// Stop profiling and send data
Perfbase::stopProfiling();
```

### Caching Strategies

Perfbase supports three caching strategies for profile data:

1. **None (Default)**: Profiles are sent directly to the API
2. **Database**: Profiles are stored in your database and synced periodically
3. **File**: Profiles are stored as files and synced periodically

To use database or file caching, update your `.env`:

```env
PERFBASE_CACHE=database
# OR
PERFBASE_CACHE=file
```

### Available Commands

```bash
# Manually sync cached profiles to the API
php artisan perfbase:sync-profiles

# Clear all cached profiles
php artisan perfbase:clear
```

## Requirements

- PHP 8.0 or higher
- Laravel 8.0 or higher
- Perfbase PHP extension

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email security@perfbase.com instead of using the issue tracker.

## License

The Apache License Version 2.0. Please see [License File](LICENSE.txt) for more information.
