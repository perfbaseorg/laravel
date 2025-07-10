<?php

namespace Perfbase\Laravel\Profiling;

/**
 * Universal profiler that can handle any type of profiling context
 */
class UniversalProfiler extends AbstractProfiler
{
    /** @var array<string, mixed> */
    private array $context;
    
    /** @var callable|null */
    private $shouldProfileCallback;

    /**
     * @param string $type The type of profiling (http, console, queue, etc.)
     * @param array<string, mixed> $context Context data for the profiling session
     * @param callable|null $shouldProfileCallback Optional custom logic for shouldProfile
     */
    public function __construct(string $type, array $context = [], ?callable $shouldProfileCallback = null)
    {
        parent::__construct($type);
        $this->context = $context;
        $this->shouldProfileCallback = $shouldProfileCallback;
        
        // Set context as attributes (convert non-string values to strings)
        $this->setAttributes($this->convertContextToAttributes($context));
    }

    /**
     * Determine if the current context should be profiled
     *
     * @return bool
     */
    protected function shouldProfile(): bool
    {
        // Use custom callback if provided
        if ($this->shouldProfileCallback) {
            return call_user_func($this->shouldProfileCallback, $this->context);
        }
        
        // Default: check if this type of profiling is enabled
        return config("perfbase.profile.{$this->spanName}", true);
    }

    /**
     * Get the context data
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Add context data
     *
     * @param array<string, mixed> $context
     * @return void
     */
    public function addContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
        $this->setAttributes($this->convertContextToAttributes($context));
    }
    
    /**
     * Convert context array to attributes (strings only)
     *
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function convertContextToAttributes(array $context): array
    {
        $attributes = [];
        
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $attributes[$key] = $value;
            } elseif (is_scalar($value)) {
                $attributes[$key] = (string) $value;
            } elseif (is_array($value)) {
                $attributes[$key] = json_encode($value) ?: '[]';
            } else {
                $attributes[$key] = serialize($value) ?: '';
            }
        }
        
        return $attributes;
    }
}