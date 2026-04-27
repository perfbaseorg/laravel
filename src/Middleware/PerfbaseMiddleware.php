<?php

namespace Perfbase\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Perfbase\Laravel\Lifecycle\HttpTraceLifecycle;
use Perfbase\Laravel\Support\PerfbaseErrorHandling;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PerfbaseMiddleware
{
    use PerfbaseErrorHandling;

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('perfbase.enabled')) {
            return $next($request);
        }

        try {
            $lifecycle = new HttpTraceLifecycle($request);
            $lifecycle->startProfiling();
        } catch (Throwable $e) {
            $this->handleProfilingError($e, 'http_start');

            return $next($request);
        }

        try {
            /** @var Response $response */
            $response = $next($request);

            $lifecycle->setResponse($response);
            $lifecycle->stopProfiling();

            return $response;
        } catch (Throwable $e) {
            $lifecycle->setException($e->getMessage());
            $lifecycle->stopProfiling();

            throw $e;
        }
    }
}
