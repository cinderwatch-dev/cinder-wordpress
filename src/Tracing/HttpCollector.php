<?php

namespace Cinderwatch\WordPress\Tracing;

use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Tracing\SpanCategory;
use Cinderwatch\WordPress\Cinder;

class HttpCollector
{
    /**
     * Start times of in-flight requests, keyed by URL. WordPress passes filter
     * arguments by value, so the start cannot travel with the request args.
     *
     * @var array<string, list<int>>
     */
    private array $pending = [];

    public function register(): void
    {
        if (! Cinder::client()->config()->reportTraces) {
            return;
        }

        add_filter('pre_http_request', $this->onPreRequest(...), PHP_INT_MAX, 3);
        add_action('http_api_debug', $this->onDebug(...), 10, 5);
    }

    /**
     * @param  mixed  $pre
     * @param  array<string, mixed>  $parsedArgs
     */
    public function onPreRequest($pre, array $parsedArgs, string $url): mixed
    {
        if ($pre === false && ! $this->isCinderIngestUrl($url)) {
            $this->pending[$url][] = Time::unixNano();
        }

        return $pre;
    }

    /**
     * @param  mixed  $response
     * @param  array<string, mixed>  $parsedArgs
     */
    public function onDebug($response, string $context, string $class, array $parsedArgs, string $url): void
    {
        if ($context !== 'response' || $this->isCinderIngestUrl($url)) {
            return;
        }

        $end = Time::unixNano();
        $start = isset($this->pending[$url]) && $this->pending[$url] !== []
            ? array_pop($this->pending[$url])
            : $end;

        $status = match (true) {
            is_wp_error($response) => 503,
            is_array($response) => (int) wp_remote_retrieve_response_code($response),
            default => 0,
        };

        $method = isset($parsedArgs['method']) && is_string($parsedArgs['method'])
            ? strtoupper($parsedArgs['method'])
            : 'GET';

        Cinder::client()->tracer()->recordCompleted('HTTP - '.$this->hostFromUrl($url), [
            TelemetryAttributeKeys::SpanKind => SpanCategory::OutboundHttp->value,
            'url.full' => $url,
            'http.request.method' => $method,
            'http.response.status_code' => $status,
        ], $start, $end);
    }

    private function isCinderIngestUrl(string $url): bool
    {
        $baseHost = parse_url(Cinder::client()->config()->baseUrl, PHP_URL_HOST);

        return is_string($baseHost) && $baseHost !== '' && parse_url($url, PHP_URL_HOST) === $baseHost;
    }

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }
}
