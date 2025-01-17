<?php

namespace Perfbase\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Perfbase\SDK\Tracing\TraceInstance;

class PerfbaseProfilingEnded
{
    use Dispatchable;

    public TraceInstance $instance;

    public function __construct(TraceInstance $instance)
    {
        $this->instance = $instance;
    }
}