<?php

namespace Perfbase\Laravel\Middleware;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use JsonException;
use Perfbase\Laravel\Profiling\HttpProfiler;
use Perfbase\SDK\Exception\PerfbaseException;
use Perfbase\SDK\Exception\PerfbaseExtensionException;
use Perfbase\SDK\Exception\PerfbaseInvalidSpanException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class PerfbaseMiddleware
 *
 * Middleware to handle request profiling using Perfbase.
 */
class PerfbaseMiddleware
{
    /**
     * Handle the incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     * @throws BindingResolutionException
     * @throws JsonException
     * @throws PerfbaseException
     * @throws PerfbaseExtensionException
     * @throws PerfbaseInvalidSpanException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if profiling is enabled
        if (!config('perfbase.enabled')) {
            // No profiling enabled, just pass the request
            /** @var Response $response */
            return $next($request);
        }

        // Profiler is enabled, start profiling
        $profiler = new HttpProfiler($request);
        $profiler->startProfiling();
        /** @var Response $response */
        $response = $next($request);
        $profiler->setResponse($response);
        $profiler->stopProfiling();
        return $response;

    }
}
