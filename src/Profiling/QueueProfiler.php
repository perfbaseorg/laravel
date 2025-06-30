<?php

namespace Perfbase\Laravel\Profiling;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Queue\Job;
use Perfbase\SDK\Exception\PerfbaseException;

/**
 * Class QueueProfiler
 *
 * Handles Queue profiling using the Perfbase SDK.
 */
class QueueProfiler extends AbstractProfiler
{
    /** @var Job */
    private Job $job;

    /** @var string */
    private string $jobName;

    /**
     * @param Job $job
     * @param string $jobName
     * @throws BindingResolutionException
     */
    public function __construct(Job $job, string $jobName)
    {
        parent::__construct('queue');
        $this->job = $job;
        $this->jobName = $jobName;
    }

    /**
     * Set the exception message for the job.
     *
     * @param string $exception
     * @return void
     */
    public function setException(string $exception): void
    {
        $this->setAttribute('exception', $exception);
    }

    /**
     * Determine if the current context should be profiled
     *
     * @return bool
     * @throws PerfbaseException
     */
    protected function shouldProfile(): bool
    {
        if (!config('perfbase.enabled', false)) {
            return false;
        }

        $includes = config('perfbase.include.queue', []);
        if (!is_array($includes)) {
            throw new PerfbaseException('Configured perfbase queue `includes` must be an array.');
        }

        if (!empty($includes) && !in_array($this->jobName, $includes, true)) {
            return false;
        }

        $excludes = config('perfbase.exclude.queue', []);
        if (!is_array($excludes)) {
            throw new PerfbaseException('Configured perfbase queue `excludes` must be an array.');
        }

        if (!empty($excludes) && in_array($this->jobName, $excludes, true)) {
            return false;
        }

        return true;
    }

    /**
     * Set default attributes that should be included in every trace
     *
     * @return void
     * @throws PerfbaseException
     */
    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        // Add queue specific attributes
        $this->setAttributes([
            'source' => 'queue',
            'action' => $this->jobName,
            'queue' => $this->job->getQueue(),
            'attempts' => (string)($this->job->attempts() ?? 0),
            'connection' => $this->job->getConnectionName(),
            'job_id' => $this->job->getJobId(),
        ]);
    }
}
