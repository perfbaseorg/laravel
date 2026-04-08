<?php

namespace Perfbase\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Perfbase\Laravel\Lifecycle\HttpTraceLifecycle;
use Symfony\Component\HttpFoundation\Response;

class PerfbaseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('perfbase.enabled')) {
            return $next($request);
        }

        $lifecycle = new HttpTraceLifecycle($request);
        $lifecycle->startProfiling();

        /** @var Response $response */
        $response = $next($request);

        $lifecycle->setResponse($response);
        $lifecycle->stopProfiling();

        return $response;
    }
}
