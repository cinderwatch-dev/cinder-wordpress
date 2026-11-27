<?php

namespace Cinderwatch\WordPress\Errors;

use Cinderwatch\Errors\ReportEnricher;
use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\WordPress\EntryPoint;
use Throwable;

/**
 * Adds WordPress specifics to error reports: entry point, active theme,
 * multisite blog, the request and user, and the plugin or theme the error
 * most likely came from.
 */
class WordPressContextEnricher implements ReportEnricher
{
    public function attributesFor(Throwable $throwable): array
    {
        $type = EntryPoint::type();
        $path = parse_url(EntryPoint::requestUri(), PHP_URL_PATH);

        $attributes = [
            ...$this->siteAttributes(),
            TelemetryAttributeKeys::EntryKind => EntryPoint::kind(),
            'wordpress.request_type' => $type,
        ];

        // WP-CLI command names come from the request tracer's entry span.
        if ($type !== 'cli') {
            $attributes[TelemetryAttributeKeys::EntryName] = $type === 'cron'
                ? 'wp-cron'
                : (is_string($path) && $path !== '' ? $path : '/');
        }

        if (! EntryPoint::isCli()) {
            $attributes = [...$attributes, ...$this->requestAttributes(), ...$this->userAttributes()];
        }

        $component = $this->culpritComponent($throwable);

        if ($component !== null) {
            $attributes['wordpress.plugin'] = $component;
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private function siteAttributes(): array
    {
        $attributes = ['wordpress.theme' => get_stylesheet()];

        if (is_multisite()) {
            $attributes['wordpress.blog_id'] = (string) get_current_blog_id();
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private function requestAttributes(): array
    {
        $attributes = [];
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if (is_string($method)) {
            $attributes['http.request.method'] = $method;
        }

        $attributes['url.full'] = home_url(EntryPoint::requestUri());
        $attributes['url.path'] = EntryPoint::requestUri();

        if (is_string($agent)) {
            $attributes['user_agent.original'] = $agent;
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private function userAttributes(): array
    {
        // pluggable.php loads after plugins, so errors raised while they load have no user yet.
        if (! function_exists('wp_get_current_user')) {
            return [];
        }

        $user = wp_get_current_user();

        return $user->exists()
            ? ['user.id' => (string) $user->ID, 'user.email' => (string) $user->user_email]
            : [];
    }

    /**
     * The slug of the first plugin found in the trace, else the first theme.
     */
    private function culpritComponent(Throwable $throwable): ?string
    {
        $files = [$throwable->getFile()];

        foreach ($throwable->getTrace() as $entry) {
            if (is_string($entry['file'] ?? null)) {
                $files[] = $entry['file'];
            }
        }

        foreach (['/plugins/', '/themes/'] as $directory) {
            foreach ($files as $file) {
                $slug = $this->slugAfter($file, $directory);

                if ($slug !== null) {
                    return $slug;
                }
            }
        }

        return null;
    }

    private function slugAfter(string $file, string $directory): ?string
    {
        $offset = strpos($file, $directory);

        if ($offset === false) {
            return null;
        }

        $slug = strtok(substr($file, $offset + strlen($directory)), '/');

        return is_string($slug) ? $slug : null;
    }
}
