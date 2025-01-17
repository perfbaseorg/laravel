<?php

namespace Perfbase\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PerfbaseProfilingStarting
{
    use Dispatchable;

    public function __construct()
    {
        //
    }
}