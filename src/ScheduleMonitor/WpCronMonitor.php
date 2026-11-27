<?php

namespace Cinderwatch\WordPress\ScheduleMonitor;

use Cinderwatch\ScheduleMonitor\HeartbeatEvent;
use Cinderwatch\ScheduleMonitor\ScheduleClient;
use Cinderwatch\ScheduleMonitor\ScheduledTask;
use Cinderwatch\WordPress\Config\WordPressConfigResolver;
use Cinderwatch\WordPress\EntryPoint;
use Cinderwatch\WordPress\Http\WordPressHttpClient;

/**
 * Mirrors recurring WP-Cron hooks as Cinder scheduled tasks keyed by hook name
 * and sends a heartbeat as each one runs. Two non-autoloaded options hold the
 * hook to task id map and a hash of the task list, used to spot when to re-sync.
 */
class WpCronMonitor
{
    private const MAP_OPTION = 'cinder_schedule_monitor_map';

    private const HASH_OPTION = 'cinder_schedule_monitor_hash';

    /** Keeps the stored options small on sites with a lot of cron hooks. */
    private const MAX_TRACKED_HOOKS = 500;

    private const MAX_ERROR_LENGTH = 1000;

    private static ?self $instance = null;

    private ?string $currentHook = null;

    private ?float $currentHookStartedAt = null;

    /** @var array<string, string> */
    private array $hookMap = [];

    /** @var list<string> */
    private array $registeredHooks = [];

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public function enabled(): bool
    {
        if (! WordPressConfigResolver::scheduleMonitorEnabled()) {
            return false;
        }

        return WordPressConfigResolver::resolve()->key !== '';
    }

    public function register(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $map = get_option(self::MAP_OPTION, []);

        if (is_array($map)) {
            /** @var array<string, string> $map */
            $this->hookMap = $map;
            $this->registerHookCallbacks();
        }

        add_action('init', $this->maybeSync(...), 20);
    }

    public function activate(): void
    {
        $this->sync();
    }

    /**
     * @return list<array{key: string, id: string, heartbeat_url: string}>
     */
    public function sync(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $tasks = $this->buildTasks();
        $registered = $this->client()->sync($tasks);

        $this->hookMap = array_slice(array_column($registered, 'id', 'key'), 0, self::MAX_TRACKED_HOOKS, true);
        $this->registerHookCallbacks();

        update_option(self::MAP_OPTION, $this->hookMap, false);
        update_option(self::HASH_OPTION, $this->hashTasks($tasks), false);

        return $registered;
    }

    public function maybeSync(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $storedHash = (string) get_option(self::HASH_OPTION, '');

        if ($storedHash !== $this->hashTasks($this->buildTasks())) {
            $this->sync();
        }
    }

    public function reportCurrentHookFailed(string $message): void
    {
        if ($this->currentHook === null) {
            return;
        }

        $this->heartbeat($this->currentHook, HeartbeatEvent::Failed, [
            'duration_ms' => $this->elapsedMilliseconds(),
            'memory_bytes' => memory_get_peak_usage(true),
            'exit_code' => 1,
            'error' => mb_substr($message, 0, self::MAX_ERROR_LENGTH),
        ]);

        $this->currentHook = null;
        $this->currentHookStartedAt = null;
    }

    /**
     * @param  array{duration_ms?: int|null, memory_bytes?: int|null, exit_code?: int|null, error?: string|null}  $metrics
     */
    public function heartbeat(string $hook, HeartbeatEvent $event, array $metrics = []): void
    {
        $taskId = $this->hookMap[$hook] ?? null;

        if (! is_string($taskId) || $taskId === '') {
            return;
        }

        $this->client()->heartbeat($taskId, $event, $metrics);
    }

    private function registerHookCallbacks(): void
    {
        foreach (array_keys($this->hookMap) as $hook) {
            if (in_array($hook, $this->registeredHooks, true)) {
                continue;
            }

            $this->registeredHooks[] = $hook;

            add_action($hook, function () use ($hook): void {
                if (! EntryPoint::isCron()) {
                    return;
                }

                $this->currentHook = $hook;
                $this->currentHookStartedAt = microtime(true);
                $this->heartbeat($hook, HeartbeatEvent::Started);
            }, PHP_INT_MIN);

            add_action($hook, function () use ($hook): void {
                if (! EntryPoint::isCron()) {
                    return;
                }

                $this->heartbeat($hook, HeartbeatEvent::Succeeded, [
                    'duration_ms' => $this->elapsedMilliseconds(),
                    'memory_bytes' => memory_get_peak_usage(true),
                    'exit_code' => 0,
                ]);
                $this->currentHook = null;
                $this->currentHookStartedAt = null;
            }, PHP_INT_MAX);
        }
    }

    private function elapsedMilliseconds(): ?int
    {
        return $this->currentHookStartedAt === null
            ? null
            : (int) round((microtime(true) - $this->currentHookStartedAt) * 1000);
    }

    private function client(): ScheduleClient
    {
        $config = WordPressConfigResolver::resolve();

        return new ScheduleClient(
            apiToken: $config->key,
            apiBaseUrl: $config->apiPrefix(),
            client: new WordPressHttpClient($config),
        );
    }

    /**
     * @return list<ScheduledTask>
     */
    private function buildTasks(): array
    {
        $tasks = [];
        $schedules = array_slice($this->discoverHookSchedules(), 0, self::MAX_TRACKED_HOOKS, true);
        $graceMinutes = WordPressConfigResolver::cronGraceMinutes();
        $timezone = wp_timezone_string();

        foreach ($schedules as $hook => $intervalMinutes) {
            $tasks[] = ScheduledTask::interval(
                key: $hook,
                minutes: $intervalMinutes,
                timezone: $timezone,
                graceMinutes: $graceMinutes,
                summary: 'WP-Cron hook',
            );
        }

        return $tasks;
    }

    /**
     * @param  list<ScheduledTask>  $tasks
     */
    private function hashTasks(array $tasks): string
    {
        return md5(json_encode(
            array_map(fn (ScheduledTask $task): array => $task->toPayload(), $tasks),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * The shortest interval, in minutes, of each recurring hook. One-off events have no schedule.
     *
     * @return array<string, int>
     */
    private function discoverHookSchedules(): array
    {
        $wpSchedules = wp_get_schedules();
        $hooks = [];

        foreach (_get_cron_array() as $hooksAtTime) {
            foreach ($hooksAtTime as $hook => $events) {
                foreach ($events as $event) {
                    $schedule = $event['schedule'] ?? false;

                    if (! is_string($schedule) || $schedule === '') {
                        continue;
                    }

                    $interval = (int) ($wpSchedules[$schedule]['interval'] ?? 3600);
                    $minutes = max(1, (int) ceil($interval / 60));

                    $hooks[$hook] = min($hooks[$hook] ?? $minutes, $minutes);
                }
            }
        }

        return $hooks;
    }
}
