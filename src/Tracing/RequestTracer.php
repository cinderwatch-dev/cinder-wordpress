<?php

namespace Cinderwatch\WordPress\Tracing;

use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Tracing\Span;
use Cinderwatch\Tracing\SpanCategory;
use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\EntryPoint;
use WP_CLI;

class RequestTracer
{
    private ?Span $rootSpan = null;

    public function register(): void
    {
        if (! Cinder::client()->config()->reportTraces) {
            return;
        }

        add_action('init', $this->start(...), 0);
        add_action('shutdown', $this->finish(...), 9999);
    }

    public function start(): void
    {
        $tracer = Cinder::client()->tracer();

        if (EntryPoint::isCli()) {
            $command = $this->cliCommand();

            $this->rootSpan = $tracer->open('Command - '.$command, [
                TelemetryAttributeKeys::SpanKind => SpanCategory::ConsoleCommand->value,
                TelemetryAttributeKeys::EntryKind => 'cli',
                TelemetryAttributeKeys::EntryName => $command,
                TelemetryAttributeKeys::EntryHandler => $command,
                'process.command' => $command,
            ]);

            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $path = parse_url(EntryPoint::requestUri(), PHP_URL_PATH);

        $this->rootSpan = $tracer->open($this->spanName(), [
            TelemetryAttributeKeys::SpanKind => SpanCategory::WebRequest->value,
            TelemetryAttributeKeys::EntryKind => EntryPoint::kind(),
            TelemetryAttributeKeys::EntryName => is_string($path) && $path !== '' ? $path : '/',
            TelemetryAttributeKeys::EntryRoute => $this->routeTemplate(),
            'wordpress.request_type' => EntryPoint::type(),
            'http.request.method' => is_string($method) ? strtoupper($method) : 'GET',
            'url.full' => home_url(EntryPoint::requestUri()),
        ]);
    }

    public function finish(): void
    {
        if ($this->rootSpan === null) {
            return;
        }

        if (! EntryPoint::isCli()) {
            $this->rootSpan->annotate([
                'http.response.status_code' => http_response_code() ?: 200,
                TelemetryAttributeKeys::EntryRoute => $this->routeTemplate(),
            ]);
        }

        Cinder::client()->tracer()->close($this->rootSpan);
        $this->rootSpan = null;
    }

    /**
     * Only the registered command path (e.g. `wp option get`), so arguments such
     * as `wp eval` code or option values never leave the site.
     */
    private function cliCommand(): string
    {
        $arguments = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? array_slice($GLOBALS['argv'], 1) : [];
        $command = WP_CLI::get_root_command();
        $path = [];

        foreach ($arguments as $argument) {
            if (! is_string($argument) || str_starts_with($argument, '-')) {
                continue;
            }

            $subcommands = $command->get_subcommands();

            if (! isset($subcommands[$argument])) {
                break;
            }

            $path[] = $argument;
            $command = $subcommands[$argument];
        }

        return trim('wp '.implode(' ', $path));
    }

    /**
     * The matched REST route, e.g. `/wp/v2/posts/(?P<id>[\d]+)`.
     */
    private function routeTemplate(): ?string
    {
        if (! EntryPoint::isRest()) {
            return null;
        }

        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;

        return is_string($route) && $route !== '' ? $route : null;
    }

    private function spanName(): string
    {
        if (EntryPoint::isCron()) {
            return 'Request - wp-cron';
        }

        $route = $this->routeTemplate();

        if ($route !== null) {
            return 'Request - '.$route;
        }

        if (is_admin()) {
            $page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'admin';

            return 'Request - '.$page;
        }

        $path = parse_url(EntryPoint::requestUri(), PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return 'Request - /';
        }

        return 'Request - '.($path === '/' ? $path : rtrim($path, '/'));
    }
}
