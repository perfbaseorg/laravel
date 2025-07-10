<?php

namespace Perfbase\Laravel\Support;

use Throwable;

/**
 * Unified error handling for Perfbase components
 */
trait PerfbaseErrorHandling
{
    /**
     * Handle extension-related errors
     *
     * @param Throwable $e
     * @return void
     */
    protected function handleExtensionError(Throwable $e): void
    {
        if (PerfbaseConfig::get('debug', false)) {
            throw $e;
        }
        
        // Log the error if logging is enabled
        if (PerfbaseConfig::get('log_errors', true)) {
            $logger = logger();
            if ($logger) {
                $logger->warning('Perfbase extension error: ' . $e->getMessage(), [
                    'exception' => $e,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        // Continue silently in production
    }

    /**
     * Handle profiling errors
     *
     * @param Throwable $e
     * @param string $context
     * @return void
     */
    protected function handleProfilingError(Throwable $e, string $context = ''): void
    {
        if (PerfbaseConfig::get('debug', false)) {
            throw $e;
        }
        
        // Log the error with context
        if (PerfbaseConfig::get('log_errors', true)) {
            $logger = logger();
            if ($logger) {
                $logger->warning("Perfbase profiling error in {$context}: " . $e->getMessage(), [
                    'exception' => $e,
                    'context' => $context
                ]);
            }
        }
    }
}