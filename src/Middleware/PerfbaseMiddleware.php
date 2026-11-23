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
        } catch (Throwable $e) {
            // Application exception: record it, finish the trace, and rethrow
            // the application's exception — never a profiling one.
            $this->finishProfiling($lifecycle, null, $e);

            throw $e;
        }

        $this->finishProfiling($lifecycle, $response, null);

        return $response;
    }

    private function finishProfiling(HttpTraceLifecycle $lifecycle, ?Response $response, ?Throwable $exception): void
    {
        try {
            if ($response !== null) {
                $lifecycle->setResponse($response);
            }

            if ($exception !== null) {
                $lifecycle->setException($exception->getMessage());
            }

            $lifecycle->stopProfiling();
        } catch (Throwable $e) {
            $this->handleProfilingError($e, 'http_stop');
        }
    }
}
