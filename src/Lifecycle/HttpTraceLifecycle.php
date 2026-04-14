<?php

namespace Perfbase\Laravel\Lifecycle;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Perfbase\Laravel\Interfaces\ProfiledUser;
use Perfbase\Laravel\Profiling\AbstractProfiler;
use Perfbase\Laravel\Support\FilterMatcher;
use Perfbase\Laravel\Support\SpanNaming;
use Perfbase\SDK\Utils\EnvironmentUtils;
use Symfony\Component\HttpFoundation\Response;

class HttpTraceLifecycle extends AbstractProfiler
{
    private Request $request;

    public function __construct(Request $request)
    {
        parent::__construct(SpanNaming::forHttp($request));
        $this->request = $request;
    }

    public function setResponse(Response $response): void
    {
        $this->setAttribute('http_status_code', (string) $response->getStatusCode());
        $this->setAttribute('action', $this->resolveAction());
    }

    protected function shouldProfile(): bool
    {
        if (!config('perfbase.enabled', false)) {
            return false;
        }

        /** @var Authenticatable|null $user */
        $user = $this->request->user();
        if ($user instanceof ProfiledUser && !$user->shouldBeProfiled()) {
            return false;
        }

        $components = $this->getRequestComponents();
        if (!FilterMatcher::passesConfigFilters($components, 'http')) {
            return false;
        }

        if (!$this->perfbase->isExtensionAvailable()) {
            return false;
        }

        return true;
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'http',
            'action' => $this->resolveAction(),
            'http_method' => $this->request->method(),
            'http_url' => $this->request->fullUrl(),
            'user_ip' => EnvironmentUtils::getUserIp() ?? '',
            'user_agent' => EnvironmentUtils::getUserUserAgent() ?? '',
        ]);

        if (Auth::check()) {
            $this->setAttribute('user_id', (string) Auth::id());
        }
    }

    /**
     * @return array<string>
     */
    private function getRequestComponents(): array
    {
        $pathWithSlash = '/' . ltrim($this->request->path(), '/');
        $components = [
            sprintf('%s %s', $this->request->method(), $pathWithSlash),
            sprintf('%s %s', $this->request->method(), $this->request->path()),
            $this->request->path(),
            $pathWithSlash,
        ];

        $route = $this->request->route();
        if ($route instanceof Route) {
            $explodedAction = explode('@', $route->getActionName());
            $components[] = $route->getActionName();
            $components[] = $route->uri();
            $components[] = '/' . ltrim($route->uri(), '/');
            $components[] = $explodedAction[0];

            foreach ($route->methods() as $method) {
                $components[] = sprintf('%s %s', $method, $route->uri());
                $components[] = sprintf('%s %s', $method, '/' . ltrim($route->uri(), '/'));
            }
        }

        return $components;
    }

    private function resolveAction(): string
    {
        $route = $this->request->route();

        if ($route instanceof Route) {
            return sprintf('%s %s', $this->request->method(), $route->uri());
        }

        return sprintf('%s %s', $this->request->method(), $this->request->path());
    }
}
