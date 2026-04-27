<?php

namespace Perfbase\Laravel\Support;

use Illuminate\Support\Str;

/**
 * Shared filter matching for include/exclude pattern lists.
 *
 * Supports wildcards (*, .*), regex (/pattern/), and Laravel's Str::is().
 */
class FilterMatcher
{
    /**
     * Check if any component matches any filter pattern.
     *
     * @param array<string> $components Values to test (e.g. route path, job name, command name)
     * @param array<string> $filters Patterns to match against
     * @return bool
     */
    public static function matches(array $components, array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($filter === '*' || $filter === '.*') {
                return true;
            }

            // Regex patterns enclosed in forward slashes
            if (preg_match('/^\/.*\/$/', $filter)) {
                foreach ($components as $component) {
                    if (preg_match($filter, $component)) {
                        return true;
                    }
                }
                continue;
            }

            // Laravel string matching for everything else
            foreach ($components as $component) {
                if (Str::is($filter, $component)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a value passes include/exclude filters from config.
     *
     * @param array<string> $components Values to test
     * @param string $configKey Config key prefix (e.g. 'http', 'artisan', 'jobs')
     * @return bool
     */
    public static function passesConfigFilters(array $components, string $configKey): bool
    {
        /** @var array<string> $includes */
        $includes = config("perfbase.include.{$configKey}", []);
        if (!is_array($includes) || empty($includes)) {
            return false;
        }

        if (!self::matches($components, $includes)) {
            return false;
        }

        /** @var array<string> $excludes */
        $excludes = config("perfbase.exclude.{$configKey}", []);
        if (is_array($excludes) && !empty($excludes) && self::matches($components, $excludes)) {
            return false;
        }

        return true;
    }
}
