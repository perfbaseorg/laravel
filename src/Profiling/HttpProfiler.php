<?php

namespace Perfbase\Laravel\Profiling;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Perfbase\Laravel\Interfaces\ProfiledUser;
use Perfbase\SDK\Perfbase as PerfbaseClient;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class HttpProfiler
 *
 * Handles HTTP request profiling using the Perfbase SDK.
 */
class HttpProfiler extends AbstractProfiler
{
    /** @var Request */
    private Request $request;

    public function __construct(Request $request)
    {
        parent::__construct('http');
        $this->request = $request;
    }

    public function setResponse(Response $response): void
    {
        $this->setAttribute('http_status_code', (string)$response->getStatusCode());
    }

    /**
     * Determine if the current request should be profiled.
     * This is determined by four factors:
     * 1. Whether HTTP profiling is enabled in the configuration.
     * 2. Whether the user should be profiled (if applicable).
     * 3. Whether the requested route matches the include and exclude filters.
     * 4. Whether the Perfbase extension is loaded.
     *
     * @return bool
     */
    protected function shouldProfile(): bool
    {
        if (!config('perfbase.enabled', false)) {
            return false;
        }


        /** @var Authenticatable $user */
        $user = $this->request->user();

        // Check if the user should be profiled
        if (!$this->shouldUserBeProfiled($user)) {
            return false;
        }

        // Get request components and check against filters
        $components = $this->getRequestComponents();
        if (!$this->shouldRouteBeProfiled($components)) {
            return false;
        }

        // Finally, check if the extension is actually loaded.
        $extensionReady = PerfbaseClient::isAvailable();
        if (!$extensionReady) {
            throw new RuntimeException('Profiling was requested, but the Perfbase extension is not loaded.');
        }

        return true;
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        // Set route information if available
        $route = $this->request->route();
        $action = 'Unknown HTTP Action';
        if ($route instanceof Route) {
            $action = sprintf('%s %s', $this->request->method(), $route->uri());
        }

        // Add HTTP specific attributes
        $this->setAttributes([
            'source' => 'http',
            'http_method' => $this->request->method(),
            'http_url' => $this->request->fullUrl(),
            'action' => $action,
        ]);

        // Set user ID if authenticated
        if (Auth::check()) {
            $this->setAttribute('user', (string)Auth::id());
        }
    }

    /**
     * Check if the user should be profiled.
     * @param Authenticatable|null $user
     * @return bool
     */
    private function shouldUserBeProfiled(?Authenticatable $user): bool
    {
        // Check if the user is authenticated and implements the ProfiledUser interface
        if ($user && method_exists($user, 'shouldBeProfiled')) {
            return $user->shouldBeProfiled();
        }

        // If the user is not authenticated or doesn't implement the interface, return true
        return true;
    }

    /**
     * Get components related to the request (path, controller, method)
     * @return array<string>
     */
    private function getRequestComponents(): array
    {
        $pathWithSlash = '/' . ltrim($this->request->path(), '/');
        $components = [
            sprintf("%s %s", $this->request->method(), $pathWithSlash),
            sprintf("%s %s", $this->request->method(), $this->request->path()),
            $this->request->path(),
            $pathWithSlash
        ];

        $route = $this->request->route();
        if ($route instanceof Route) {
            $explodedAction = explode('@', $route->getActionName());
            $components[] = $route->getActionName();
            $components[] = $route->uri();
            $components[] = '/' . ltrim($route->uri(), '/');
            $components[] = $explodedAction[0];

            foreach ($route->methods() as $method) {
                $components[] = sprintf("%s %s", $method, $route->uri());
                $components[] = sprintf("%s %s", $method, '/' . ltrim($route->uri(), '/'));
            }
        }

        return $components;
    }

    /**
     * Check if the route should be profiled based on include and exclude filters.
     * @param array<string> $components
     * @return bool
     */
    private function shouldRouteBeProfiled(array $components): bool
    {
        return $this->matchesIncludeFilters($components)
            && !$this->matchesExcludeFilters($components);
    }

    /**
     * Check if any include filters match the request components.
     * @param array<string> $components
     * @return bool
     */
    private function matchesIncludeFilters(array $components): bool
    {

        /** @var array<string> $includes */
        $includes = config('perfbase.include.http', []);

        // Check if includes are an array
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
     * Check if any exclude filters match the request components.
     *
     * @param array<string> $components
     * @return bool
     */
    private function matchesExcludeFilters(array $components): bool
    {
        $excludes = config('perfbase.exclude.http', []);
        if (!is_array($excludes)) {
            throw new RuntimeException('Configured perfbase HTTP `excludes` must be an array.');
        }

        return !empty($excludes) && $this->matchesFilters($components, $excludes);
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
     * @return bool Returns true if any component matches any filter; otherwise, false.
     */
    public static function matchesFilters(array $components, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (self::isMatchAllWildcard($filter)) {
                return true;
            }

            $regex = self::constructRegexFromFilter($filter);
            foreach ($components as $component) {
                if (preg_match($regex, $component)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determines if the provided filter is a match-all wildcard.
     *
     * @param string $filter The filter string to evaluate.
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
            return '/^' . str_replace('\*', '.*', $escapedFilter) . '$/';
        }

        // **Namespace Prefix Filter:** If the filter contains "\", treat it as a namespace prefix
        if (self::containsNamespaceSeparator($filter)) {
            return '/^' . $escapedFilter . '.*$/';
        }

        // **Path or Exact Match Filter:** If the filter contains "/", treat it as an exact path
        return '/^' . $escapedFilter . '$/';
    }

    /**
     * Determines if the filter is a regex pattern.
     *
     * @param string $filter The filter string to evaluate.
     * @return bool Returns true if the filter is enclosed with "/", indicating a regex; otherwise, false.
     */
    private static function isRegexFilter(string $filter): bool
    {
        return substr($filter, 0, 1) === '/' && substr($filter, -1) === '/';
    }

    /**
     * Determines if the filter contains a wildcard character "*".
     *
     * @param string $filter The filter string to evaluate.
     * @return bool Returns true if the filter contains "*"; otherwise, false.
     */
    private static function containsWildcard(string $filter): bool
    {
        return strpos($filter, '*') !== false;
    }

    /**
     * Determines if the filter contains a namespace separator "\\".
     *
     * @param string $filter The filter string to evaluate.
     * @return bool Returns true if the filter contains "\\"; otherwise, false.
     */
    private static function containsNamespaceSeparator(string $filter): bool
    {
        return strpos($filter, '\\') !== false;
    }
}
