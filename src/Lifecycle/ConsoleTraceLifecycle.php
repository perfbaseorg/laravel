<?php

namespace Perfbase\Laravel\Lifecycle;

use Perfbase\Laravel\Profiling\AbstractProfiler;
use Perfbase\Laravel\Support\FilterMatcher;
use Perfbase\Laravel\Support\SpanNaming;

class ConsoleTraceLifecycle extends AbstractProfiler
{
    private string $command;

    public function __construct(string $command)
    {
        parent::__construct(SpanNaming::forConsole($command));
        $this->command = $command;
    }

    protected function shouldProfile(): bool
    {
        return FilterMatcher::passesConfigFilters([$this->command], 'console');
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'console',
            'action' => $this->command,
        ]);
    }
}
