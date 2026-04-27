<?php

namespace Perfbase\Laravel\Lifecycle;

use Perfbase\Laravel\Profiling\AbstractProfiler;
use Perfbase\Laravel\Support\FilterMatcher;
use Perfbase\Laravel\Support\SpanNaming;

class QueueTraceLifecycle extends AbstractProfiler
{
    private string $jobName;
    private string $queue;
    private string $connection;

    public function __construct(string $jobName, string $queue, string $connection)
    {
        parent::__construct(SpanNaming::forQueue($jobName));
        $this->jobName = $jobName;
        $this->queue = $queue;
        $this->connection = $connection;
    }

    protected function shouldProfile(): bool
    {
        return FilterMatcher::passesConfigFilters([$this->jobName], 'jobs');
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'jobs',
            'action' => $this->jobName,
            'queue' => $this->queue,
            'connection' => $this->connection,
        ]);
    }
}
