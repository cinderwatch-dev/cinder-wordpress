<?php

namespace Cinderwatch\WordPress\Tracing;

use Cinderwatch\Support\TelemetryAttributeKeys;
use Cinderwatch\Support\Time;
use Cinderwatch\Tracing\SpanCategory;
use Cinderwatch\WordPress\Cinder;

/**
 * Turns `$wpdb->queries`, collected when SAVEQUERIES is on, into query spans at
 * shutdown. Each entry holds the SQL, its duration in seconds, the caller and,
 * since WordPress 5.3, the microtime the query started.
 */
class QueryCollector
{
    public function register(): void
    {
        if (! Cinder::client()->config()->reportTraces) {
            return;
        }

        add_action('shutdown', $this->recordQueries(...), 9998);
    }

    public function recordQueries(): void
    {
        if (! defined('SAVEQUERIES') || ! constant('SAVEQUERIES')) {
            return;
        }

        global $wpdb;

        if (! isset($wpdb) || ! is_array($wpdb->queries ?? null)) {
            return;
        }

        $tracer = Cinder::client()->tracer();

        if (! $tracer->isRecording()) {
            return;
        }

        $fallbackEnd = Time::unixNano();

        foreach ($wpdb->queries as $query) {
            if (! is_array($query) || ! isset($query[0], $query[1])) {
                continue;
            }

            $sql = is_string($query[0]) ? $query[0] : '';
            $duration = (int) round((is_numeric($query[1]) ? (float) $query[1] : 0.0) * 1_000_000_000);
            $startedAt = isset($query[3]) && is_numeric($query[3])
                ? Time::unixNano((float) $query[3])
                : $fallbackEnd - $duration;

            $tracer->recordCompleted('Query - '.$this->truncate($sql), [
                TelemetryAttributeKeys::SpanKind => SpanCategory::DatabaseQuery->value,
                'db.query.text' => $sql,
                'db.system.name' => 'mysql',
                'wordpress.db.caller' => isset($query[2]) && is_string($query[2]) ? $query[2] : '',
            ], $startedAt, $startedAt + $duration);
        }
    }

    private function truncate(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        if (strlen($sql) <= 120) {
            return $sql;
        }

        return substr($sql, 0, 117).'...';
    }
}
