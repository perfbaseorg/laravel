<?php

namespace Tests;

// Define the perfbase_set_attribute function in the global namespace if it doesn't exist
if (!function_exists('perfbase_set_attribute')) {
    function perfbase_set_attribute($key, $value) {
        // Mock implementation for testing
    }
}

// Make it available in the Perfbase\Laravel\Profiling namespace
namespace Perfbase\Laravel\Profiling;

if (!function_exists('Perfbase\Laravel\Profiling\perfbase_set_attribute')) {
    function perfbase_set_attribute($key, $value) {
        // Mock implementation for testing
    }
}