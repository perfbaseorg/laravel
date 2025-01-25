<?php

namespace Perfbase\Laravel\Middleware;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Perfbase\Laravel\Caching\CacheStrategyFactory;
use Perfbase\Laravel\Events\PerfbaseProfilingEnded;
use Perfbase\Laravel\Events\PerfbaseProfilingEnding;
use Perfbase\Laravel\Events\PerfbaseProfilingStarted;
use Perfbase\Laravel\Events\PerfbaseProfilingStarting;
use Perfbase\Laravel\Interfaces\ProfiledUser;
use Perfbase\SDK\Config as PerfbaseConfig;
use Perfbase\SDK\Exception\PerfbaseApiKeyMissingException;
use Perfbase\SDK\Exception\PerfbaseEncodingException;
use Perfbase\SDK\Exception\PerfbaseInvalidConfigException;
use Perfbase\SDK\Exception\PerfbaseStateException;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use Perfbase\SDK\Utils\EnvironmentUtils;
use RuntimeException;
use JsonException;
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
     * @return mixed
     * @throws BindingResolutionException
     * @throws JsonException
     * @throws PerfbaseStateException
     * @throws PerfbaseEncodingException
     * @throws PerfbaseInvalidConfigException
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if profiling should occur for this request.
        if (!$this->passesSampleRateCheck() || !$this->shouldProfile($request)) {
            return $next($request);
        }

        // Trigger pre-start event
        PerfbaseProfilingStarting::dispatch();

        /** @var Application $app */
        $app = app();

        /** @var PerfbaseConfig $config */
        $config = $app->make(PerfbaseConfig::class);

        // Start a new perfbase instance
        $instance = PerfbaseClient::createInstance($config);

        $instance->startProfiling();

        // Trigger pre-start event
        PerfbaseProfilingStarted::dispatch($instance);

        // Proceed with the request and capture the response.
        $response = $next($request);

        $route = $request->route();

        // Get the route method and URI if available.
        if ($route instanceof Route) {
            $instance->attributes->action = sprintf(
                '%s %s',
                $request->getMethod(),
                $route->uri()
            );
        } else {
            // Help! Need to know what else $route could be.
            throw new RuntimeException('Route information is not available.');
        }

        // Set user-related attributes if the user is authenticated.
        if (Auth::check()) {
            $instance->attributes->userId = (string) Auth::id();
        }

        $instance->attributes->userIp = EnvironmentUtils::getUserIp();
        $instance->attributes->userAgent = EnvironmentUtils::getUserUserAgent();
        $instance->attributes->hostname = EnvironmentUtils::getHostname();
        $instance->attributes->appVersion = "untracked"; // TODO
        $instance->attributes->phpVersion = phpversion();
        $instance->attributes->httpMethod = $request->method();

        // Set HTTP status code and URL if available.
        if ($response instanceof Response) {
            $instance->attributes->httpStatusCode = $response->getStatusCode();
            $instance->attributes->httpUrl = $request->fullUrl();
        }

        // Have we chosen to cache the profiling data for future sending
        $sendingMode = config('perfbase.sending.mode');
        $shouldSendNow = $sendingMode === 'sync';
        if (!in_array($sendingMode, ['sync', 'database', 'file'], true)) {
            throw new RuntimeException('Invalid sending mode specified in the configuration.');
        }

        // If we're not sending the data now, store it for later.
        if ($shouldSendNow === false) {
            // Yes, store it using the chosen strategy
            $cache = CacheStrategyFactory::make();

            // Transform the data
            $data = $instance->transformData();

            // Add the created_at timestamp
            $data['created_at'] = now()->toDateTimeString();

            // Store the data
            $cache->store($data);
        }

        // Trigger pre-finish event
        PerfbaseProfilingEnding::dispatch($instance);

        // Stop profiling and optionally send profiling data to Perfbase.
        $instance->stopProfiling($shouldSendNow);

        // Trigger post-finish event
        PerfbaseProfilingEnded::dispatch($instance);

        return $response;
    }

    /**
     * Determine if the current request should be profiled.
     * This is determined by four factors:
     * 1. Whether HTTP profiling is enabled in the configuration.
     * 2. Whether the user should be profiled (if applicable).
     * 3. Whether the requested route matches the include and exclude filters.
     * 4. Whether the Perfbase extension is loaded.
     *
     * @param Request $request
     * @return bool
     */
    private function shouldProfile(Request $request): bool
    {
        // Run the checks
        if (
            !config('perfbase.enabled', false) ||
            !$this->shouldUserBeProfiled($request) ||
            !$this->shouldRouteBeProfiled($request)
        ) {
            return false;
        }

        // Finally, check if the extension is actually loaded.
        $extensionReady = PerfbaseClient::isAvailable();
        if (!$extensionReady) {
            throw new RuntimeException('Profiling was requested, but the Perfbase extension is not loaded.');
        }

        return true;
    }

    /**
     * Check if the sample rate is met for the current request.
     * @return bool
     */
    private function passesSampleRateCheck(): bool {
        // Grab the sample rate from the configuration
        $sampleRate = config('perfbase.sample_rate');

        // Check if the sample rate is a valid decimal between 0.0 and 1.0
        if (!is_numeric($sampleRate) || $sampleRate < 0 || $sampleRate > 1) {
            throw new RuntimeException('Configured perfbase `sample_rate` must be a decimal between 0.0 and 1.0.');
        }

        /**
         * Generate a random decimal between 0.0 and 1.0
         * @var double $randomDecimal
         */
        $randomDecimal = mt_rand() / mt_getrandmax();

        // Check if the random decimal is less than or equal to the sample rate
        return $randomDecimal <= $sampleRate;
    }

    private function shouldUserBeProfiled(Request $request): bool
    {
        // Check if the user should be profiled. We expose a trait to allow for custom logic.
        $user = $request->user();

        if (is_object($user)) {
            $userClass = get_class($user);
            $profiledUserTrait = ProfiledUser::class;
            if (in_array($profiledUserTrait, class_implements($userClass))) {
                /** @var ProfiledUser $user */
                if (!$user->shouldBeProfiled()) {
                    return false;
                }
            }
        }



        return true;
    }

    private function shouldRouteBeProfiled(Request $request): bool
    {
        // Gather profiling components (path, controller, method).
        $components = $this->getRequestComponents($request);

        // Apply the include and exclude filters to decide profiling eligibility.
        $routeMatch = $this->matchesIncludeFilters($components) && !$this->matchesExcludeFilters($components);
        if (!$routeMatch) {
            return false;
        }
        return true;
    }

    /**
     * Get components related to the request (path, controller, method).
     *
     * @param Request $request
     * @return array<string>
     */
    private function getRequestComponents(Request $request): array
    {

        // Request based components
        $pathWithSlash = '/' . ltrim($request->path(), '/');
        $components = [
            sprintf("%s %s", $request->method(), $pathWithSlash), // GET /path
            sprintf("%s %s", $request->method(), $request->path()), // GET path
            $request->path(), // path
            $pathWithSlash // /path
        ];

        // Route based components
        $route = $request->route();
        if ($route instanceof Route) {

            $explodedAction = explode('@', $route->getActionName());
            $components[] = $route->getActionName(); // Controller@method string.
            $components[] = $route->uri(); // path
            $components[] = '/' . ltrim($route->uri(), '/'); // /path
            $components[] = $explodedAction[0]; // Controller

            /** @var string $method */
            foreach($route->methods() as $method) {
                $components[] = sprintf("%s %s", $method, $route->uri()); // GET path
                $components[] = sprintf("%s %s", $method, '/' . ltrim($route->uri(), '/')); // GET /path
            }
        }

        return $components;
    }

    /**
     * Check if any include filters match the request components.
     *
     * @param array<string> $components
     * @return bool
     * @throws RuntimeException
     */
    private function matchesIncludeFilters(array $components): bool
    {
        /** @var array<string> $includes */
        $includes = config('perfbase.include.http', []);

        // Check if includes are an array.
        if (!is_array($includes)) {
            throw new RuntimeException('Configured perfbase HTTP `includes` must be an array.');
        }

        // If no includes are set, no need to check further.
        if (empty($includes)) {
            return false;
        }

        return $this->matchesFilters($components, $includes);
    }

    /**
     * Determines if any of the provided components match any of the specified filters.
     *
     * The method supports the following filter types:
     * - **Exact Match:** e.g., "GET /example" matches exactly "GET /example".
     * - **Wildcard Match:** e.g., "GET /example/*" matches any path that starts with "GET /example/".
     * - **Regex Match:** e.g., "/^GET \/example\/([0-9]+)\/$/" matches paths like "GET /example/123/".
     * - **Namespace Prefix Match:** e.g., "App\Http\Controllers" matches any class within that namespace.
     * - **Match-All Wildcard:** "*" matches any component.
     *
     * @param array<string> $components The list of components to be matched against the filters.
     * @param array<string> $filters The list of filters to apply.
     *
     * @return bool Returns true if any component matches any filter; otherwise, false.
     */
    public static function matchesFilters(array $components, array $filters): bool
    {
        // Iterate through each filter to check against components
        foreach ($filters as $filter) {

            // **Match-All Wildcard:** Immediately return true if the filter is "*"
            if (self::isMatchAllWildcard($filter)) {
                return true;
            }

            // Determine the type of filter and construct the appropriate regex pattern
            $regex = self::constructRegexFromFilter($filter);

            // Iterate through each component to check for a match
            foreach ($components as $component) {
                if (preg_match($regex, $component)) {
                    return true;
                }
            }
        }

        // No matches found after evaluating all filters and components
        return false;
    }

    /**
     * Determines if the provided filter is a match-all wildcard.
     *
     * @param string $filter The filter string to evaluate.
     *
     * @return bool Returns true if the filter is "*"; otherwise, false.
     */
    private static function isMatchAllWildcard(string $filter): bool
    {
        return $filter === '*' || $filter === '.*';
    }

    /**
     * Constructs a regex pattern based on the provided filter.
     *
     * @param string $filter The filter string used to build the regex pattern.
     *
     * @return string The constructed regex pattern.
     */
    private static function constructRegexFromFilter(string $filter): string
    {
        // **Regex Filter:** If the filter starts and ends with "/", treat it as a regex
        if (self::isRegexFilter($filter)) {
            return $filter;
        }

        $escapedFilter = preg_quote($filter, '/');

        // **Wildcard Filter:** Convert wildcard "*" to ".*" in regex
        if (self::containsWildcard($filter)) {
            // Replace escaped "*" (\*) with ".*" to allow any characters in place of "*"
            return '/^' . str_replace('\*', '.*', $escapedFilter) . '$/';
        }

        // **Namespace Prefix Filter:** If the filter contains "\", treat it as a namespace prefix
        if (self::containsNamespaceSeparator($filter)) {
            // Allow any characters following the namespace prefix
            return '/^' . $escapedFilter . '.*$/';
        }

        // **Path or Exact Match Filter:** If the filter contains "/", treat it as an exact path
        return '/^' . $escapedFilter . '$/';
    }

    /**
     * Determines if the filter is a regex pattern.
     *
     * @param string $filter The filter string to evaluate.
     *
     * @return bool Returns true if the filter is enclosed with "/", indicating a regex; otherwise, false.
     */
    private static function isRegexFilter(string $filter): bool
    {
        return str_starts_with($filter, '/') && str_ends_with($filter, '/');
    }

    /**
     * Determines if the filter contains a wildcard character "*".
     *
     * @param string $filter The filter string to evaluate.
     *
     * @return bool Returns true if the filter contains "*"; otherwise, false.
     */
    private static function containsWildcard(string $filter): bool
    {
        return str_contains($filter, '*');
    }

    /**
     * Determines if the filter contains a namespace separator "\\".
     *
     * @param string $filter The filter string to evaluate.
     *
     * @return bool Returns true if the filter contains "\\"; otherwise, false.
     */
    private static function containsNamespaceSeparator(string $filter): bool
    {
        return str_contains($filter, '\\');
    }

    /**
     * Check if any exclude filters match the request components.
     *
     * @param array<string> $components
     * @return bool
     * @throws RuntimeException
     */
    private function matchesExcludeFilters(array $components): bool
    {
        /** @var array<string> $excludes */
        $excludes = config('perfbase.exclude.http', []);

        // Check if excludes are an array.
        if (!is_array($excludes)) {
            throw new RuntimeException('Configured perfbase HTTP `excludes` must be an array.');
        }

        // If no excludes are set, no need to check further.
        if (empty($excludes)) {
            return false;
        }

        return $this->matchesFilters($components, $excludes);
    }
}