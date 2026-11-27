<?php

use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Logs\LogListener;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

function sentLogBodies(array $sent): array
{
    $records = $sent[0]['payload']['resourceLogs'][0]['scopeLogs'][0]['logRecords'] ?? [];

    return array_map(fn (array $record): string => $record['body']['stringValue'], $records);
}

it('sends logs fired through the cinder_log action', function () {
    $transport = $this->fakeCinder();
    (new LogListener)->register();

    do_action('cinder_log', 'Order placed', 'info', ['order' => 42]);
    Cinder::client()->flush();

    expect(sentLogBodies($transport->sent))->toBe(['Order placed']);
})->group('logs');

it('leaves deprecation notices alone by default', function () {
    $this->fakeCinder();
    (new LogListener)->register();

    expect($this->hasHook('doing_it_wrong_run'))->toBeFalse()
        ->and($this->hasHook('deprecated_function_run'))->toBeFalse();
})->group('logs');

it('logs doing_it_wrong and deprecated function notices when asked to', function () {
    $transport = $this->fakeCinder();
    add_filter('cinder_capture_deprecation_logs', fn (): bool => true);
    (new LogListener)->register();

    do_action('doing_it_wrong_run', 'register_rest_route', 'Missing permission_callback.', '5.5.0');
    do_action('deprecated_function_run', 'get_page_by_title', 'WP_Query', '6.2.0');
    do_action('deprecated_function_run', 'wp_old_function', '', '6.0.0');
    Cinder::client()->flush();

    expect(sentLogBodies($transport->sent))->toBe([
        'doing_it_wrong: register_rest_route - Missing permission_callback. (since 5.5.0)',
        'deprecated_function: get_page_by_title use WP_Query (since 6.2.0)',
        'deprecated_function: wp_old_function (since 6.0.0)',
    ]);
})->group('logs');
