<?php

namespace Cinderwatch\WordPress\Tests;

use ArrayObject;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Cinderwatch\CinderClient;
use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Transport\ArrayTransport;
use Cinderwatch\WordPress\CinderManager;
use Cinderwatch\WordPress\Plugin;
use Cinderwatch\WordPress\ScheduleMonitor\WpCronMonitor;
use Closure;
use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionProperty;
use WP_CLI;
use WP_User;

/**
 * Runs each test against a small in-memory WordPress: hooks, options and
 * transients work as they do in WordPress, and constants, environment
 * variables and loaded functions can be faked per test.
 */
class TestCase extends BaseTestCase
{
    private const NAMESPACES = [
        'Cinderwatch\WordPress',
        'Cinderwatch\WordPress\Config',
        'Cinderwatch\WordPress\Errors',
        'Cinderwatch\WordPress\Health\Probes',
        'Cinderwatch\WordPress\Http',
        'Cinderwatch\WordPress\MuPlugin',
        'Cinderwatch\WordPress\Tracing',
    ];

    /** @var array<string, mixed> */
    protected array $constants = [];

    /** @var array<string, string> */
    protected array $environment = [];

    /** @var list<string> */
    protected array $missingFunctions = [];

    /** @var array<string, mixed> */
    protected array $wpOptions = [];

    /** @var array<string, mixed> */
    protected array $transients = [];

    /** @var array<string, list<array{callback: callable, priority: int, accepted_args: int}>> */
    protected array $hooks = [];

    /** @var array{error?: callable, exception?: callable, shutdown?: callable} */
    protected array $phpHandlers = [];

    /** @var array<string, mixed> */
    private array $server = [];

    /** @var array<int, string> */
    private array $argv = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Patchwork has to be loaded before the support functions so they can be redefined.
        Monkey\setUp();

        require_once __DIR__.'/Support/bootstrap.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        Monkey\setUp();

        $this->server = $_SERVER;
        $this->argv = $GLOBALS['argv'] ?? [];

        $this->fakeEnvironment();
        $this->fakePhpHandlers();
        $this->fakeHooks();
        $this->fakeStorage();

        Functions\stubs([
            'get_bloginfo' => 'Test site',
            'get_stylesheet' => 'twentytwentyfive',
            'is_admin' => false,
            'is_multisite' => false,
            'wp_get_environment_type' => 'production',
            'wp_timezone_string' => 'UTC',
            'home_url' => fn (string $path = ''): string => 'https://example.test'.$path,
            'wp_get_current_user' => fn (): WP_User => new WP_User,
        ]);
    }

    protected function tearDown(): void
    {
        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());

        Monkey\tearDown();

        $_SERVER = $this->server;
        $GLOBALS['argv'] = $this->argv;
        $_GET = [];
        $_POST = [];
        unset($GLOBALS['wpdb'], $GLOBALS['wp'], $GLOBALS['wp_version']);

        (new ReflectionProperty(Plugin::class, 'instance'))->setValue(null, null);
        (new ReflectionProperty(WpCronMonitor::class, 'instance'))->setValue(null, null);
        WP_CLI::reset();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $constants
     */
    protected function defineConstants(array $constants): void
    {
        $this->constants = [...$this->constants, ...$constants];
    }

    /**
     * @param  array<string, string>  $variables
     */
    protected function setEnvironment(array $variables): void
    {
        $this->environment = [...$this->environment, ...$variables];
    }

    protected function withoutFunctions(string ...$functions): void
    {
        $this->missingFunctions = [...$this->missingFunctions, ...$functions];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function settings(array $settings): void
    {
        $this->wpOptions['cinder_settings'] = $settings;
    }

    /**
     * Swap the plugin's client for one that keeps everything it sends in memory.
     */
    protected function fakeCinder(CinderConfig|string $config = 'project-key'): ArrayTransport
    {
        $transport = new ArrayTransport;
        $manager = new CinderManager(CinderClient::make($config, $transport), new Inspector);

        (new ReflectionProperty(Plugin::class, 'manager'))->setValue(Plugin::instance(), $manager);

        return $transport;
    }

    /**
     * Answer every wp_remote_request() with the given response and record the requests.
     *
     * @return ArrayObject<int, array{url: string, args: array<string, mixed>}>
     */
    protected function fakeHttp(int $status = 200, string $body = '{}'): ArrayObject
    {
        $requests = new ArrayObject;

        Functions\when('wp_remote_request')->alias(function (string $url, array $args) use ($requests, $status, $body): array {
            $requests[] = ['url' => $url, 'args' => $args];

            return ['response' => ['code' => $status], 'body' => $body, 'headers' => []];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn (array $response): int => $response['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn (array $response): string => $response['body']);
        Functions\when('wp_remote_retrieve_headers')->alias(fn (array $response): array => $response['headers']);

        return $requests;
    }

    /**
     * Flush the tracer and decode the spans it exported.
     *
     * @return list<array{name: string, parent: ?string, attributes: array<string, mixed>}>
     */
    protected function exportedSpans(ArrayTransport $transport): array
    {
        Plugin::instance()->manager()->client()->tracer()->flush();

        $spans = [];

        foreach ($transport->sent as $sent) {
            foreach ($sent['payload']['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [] as $span) {
                $attributes = [];

                foreach ($span['attributes'] as $attribute) {
                    $attributes[$attribute['key']] = current($attribute['value']);
                }

                $spans[] = ['name' => $span['name'], 'parent' => $span['parentSpanId'], 'attributes' => $attributes];
            }
        }

        return $spans;
    }

    protected function doAction(string $hook, mixed ...$arguments): void
    {
        foreach ($this->callbacksFor($hook) as $registered) {
            ($registered['callback'])(...array_slice($arguments, 0, $registered['accepted_args']));
        }
    }

    protected function applyFilters(string $hook, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->callbacksFor($hook) as $registered) {
            $value = ($registered['callback'])(...array_slice([$value, ...$arguments], 0, $registered['accepted_args']));
        }

        return $value;
    }

    protected function hasHook(string $hook): bool
    {
        return $this->callbacksFor($hook) !== [];
    }

    /**
     * @return list<array{callback: callable, priority: int, accepted_args: int}>
     */
    private function callbacksFor(string $hook): array
    {
        $callbacks = $this->hooks[$hook] ?? [];

        usort($callbacks, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $callbacks;
    }

    private function fakeEnvironment(): void
    {
        foreach (self::NAMESPACES as $namespace) {
            Functions\when("{$namespace}\\defined")->alias(
                fn (string $name): bool => array_key_exists($name, $this->constants) || \defined($name)
            );
            Functions\when("{$namespace}\\constant")->alias(
                fn (string $name): mixed => array_key_exists($name, $this->constants) ? $this->constants[$name] : \constant($name)
            );
            Functions\when("{$namespace}\\getenv")->alias(
                fn (string $name): string|false => $this->environment[$name] ?? false
            );
            Functions\when("{$namespace}\\function_exists")->alias(
                fn (string $name): bool => ! in_array($name, $this->missingFunctions, true) && \function_exists($name)
            );
        }
    }

    /**
     * Error handlers and shutdown functions would outlive the test, so they are only recorded.
     */
    private function fakePhpHandlers(): void
    {
        $record = fn (string $type): Closure => function (callable $handler) use ($type): null {
            $this->phpHandlers[$type] = $handler;

            return null;
        };

        Functions\when('Cinderwatch\WordPress\Errors\set_error_handler')->alias($record('error'));
        Functions\when('Cinderwatch\WordPress\Errors\set_exception_handler')->alias($record('exception'));
        Functions\when('Cinderwatch\WordPress\Errors\register_shutdown_function')->alias($record('shutdown'));
    }

    private function fakeHooks(): void
    {
        $addHook = function (string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
            $this->hooks[$hook][] = ['callback' => $callback, 'priority' => $priority, 'accepted_args' => $acceptedArgs];

            return true;
        };

        Functions\when('add_action')->alias($addHook);
        Functions\when('add_filter')->alias($addHook);
        Functions\when('do_action')->alias($this->doAction(...));
        Functions\when('apply_filters')->alias($this->applyFilters(...));
    }

    private function fakeStorage(): void
    {
        Functions\when('get_option')->alias(fn (string $name, mixed $default = false): mixed => $this->wpOptions[$name] ?? $default);
        Functions\when('update_option')->alias(function (string $name, mixed $value): bool {
            $this->wpOptions[$name] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(fn (string $name): mixed => $this->transients[$name] ?? false);
        Functions\when('set_transient')->alias(function (string $name, mixed $value): bool {
            $this->transients[$name] = $value;

            return true;
        });
    }
}
