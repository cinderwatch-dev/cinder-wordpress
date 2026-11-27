<?php

use Brain\Monkey\Functions;
use Cinderwatch\WordPress\Errors\ErrorHandler;
use Cinderwatch\WordPress\ScheduleMonitor\WpCronMonitor;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('registers its handlers once', function () {
    $handler = new ErrorHandler;

    $handler->register();
    $first = $this->phpHandlers;
    $handler->register();

    expect($this->phpHandlers)->toBe($first)
        ->and($first)->toHaveKeys(['error', 'exception', 'shutdown']);
})->group('errors');

it('reports uncaught exceptions and hands them to the previous handler', function () {
    $transport = $this->fakeCinder();
    $previous = [];
    Functions\when('Cinderwatch\WordPress\Errors\set_exception_handler')->justReturn(function (Throwable $throwable) use (&$previous): void {
        $previous[] = $throwable->getMessage();
    });

    $handler = new ErrorHandler;
    $handler->register();
    $handler->handleException(new RuntimeException('boom'));

    expect($transport->sent)->toHaveCount(1)
        ->and($previous)->toBe(['boom']);
})->group('errors');

it('does not report anything without a key', function () {
    $transport = $this->fakeCinder('');

    (new ErrorHandler)->handleException(new RuntimeException('boom'));

    expect($transport->sent)->toBe([]);
})->group('errors');

it('marks the running wp-cron hook as failed', function () {
    $this->fakeCinder();
    $this->defineConstants(['DOING_CRON' => true]);
    $requests = $this->fakeHttp();
    $this->settings(['key' => 'project-key']);

    $monitor = WpCronMonitor::instance();
    (new ReflectionProperty($monitor, 'hookMap'))->setValue($monitor, ['nightly_cleanup' => 'task-1']);
    (new ReflectionProperty($monitor, 'currentHook'))->setValue($monitor, 'nightly_cleanup');

    (new ErrorHandler)->handleException(new RuntimeException('Disk full'));

    expect($requests[0]['url'])->toEndWith('/heartbeats/task-1')
        ->and(json_decode($requests[0]['args']['body'], true))->toMatchArray(['event' => 'failed', 'exit_code' => 1, 'error' => 'Disk full']);
})->group('errors');

it('turns php warnings into reports and passes them on', function () {
    $transport = $this->fakeCinder();
    Functions\when('Cinderwatch\WordPress\Errors\set_error_handler')->justReturn(fn (): bool => true);

    $handler = new ErrorHandler;
    $handler->register();

    $reporting = error_reporting(E_ALL);
    $handled = $handler->handleError(E_WARNING, 'Undefined variable $cart', '/var/www/shop.php', 12);
    error_reporting($reporting);

    expect($handled)->toBeTrue()
        ->and($transport->sent[0]['payload'])->toBeArray()
        ->and(json_encode($transport->sent[0]['payload']))->toContain('Undefined variable $cart');
})->group('errors');

it('leaves php errors to wordpress when there is no previous handler', function () {
    $transport = $this->fakeCinder();

    $reporting = error_reporting(E_ALL);
    $handled = (new ErrorHandler)->handleError(E_WARNING, 'boom', __FILE__, 1);
    error_reporting($reporting);

    expect($handled)->toBeFalse()
        ->and($transport->sent)->toHaveCount(1);
})->group('errors');

it('ignores silenced errors and deprecations', function (int $severity) {
    $transport = $this->fakeCinder();
    $reporting = error_reporting(E_ALL & ~E_NOTICE);

    $handled = (new ErrorHandler)->handleError($severity, 'ignored', __FILE__, 1);

    error_reporting($reporting);

    expect($handled)->toBeFalse()
        ->and($transport->sent)->toBe([]);
})->with([
    'silenced notice' => E_NOTICE,
    'deprecation' => E_DEPRECATED,
    'user deprecation' => E_USER_DEPRECATED,
])->group('errors');

it('reports fatal errors at shutdown', function () {
    $transport = $this->fakeCinder();
    Functions\when('Cinderwatch\WordPress\Errors\error_get_last')->justReturn([
        'type' => E_ERROR,
        'message' => 'Allowed memory size exhausted',
        'file' => '/var/www/wp-content/plugins/acme/acme.php',
        'line' => 10,
    ]);

    (new ErrorHandler)->handleShutdown();

    expect($transport->sent)->toHaveCount(1)
        ->and(json_encode($transport->sent[0]['payload']))->toContain('Allowed memory size exhausted');
})->group('errors');

it('ignores non-fatal errors and clean shutdowns', function (?array $error) {
    $transport = $this->fakeCinder();
    Functions\when('Cinderwatch\WordPress\Errors\error_get_last')->justReturn($error);

    (new ErrorHandler)->handleShutdown();

    expect($transport->sent)->toBe([]);
})->with([
    'clean shutdown' => [null],
    'warning' => [['type' => E_WARNING, 'message' => 'boom', 'file' => __FILE__, 'line' => 1]],
])->group('errors');
