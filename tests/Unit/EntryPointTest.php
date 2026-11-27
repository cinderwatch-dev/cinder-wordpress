<?php

use Brain\Monkey\Functions;
use Cinderwatch\WordPress\EntryPoint;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('works out the entry point from the constants wordpress defines', function (string $constant, string $type, string $kind) {
    $this->defineConstants([$constant => true]);

    expect(EntryPoint::type())->toBe($type)
        ->and(EntryPoint::kind())->toBe($kind);
})->with([
    'wp-cli' => ['WP_CLI', 'cli', 'cli'],
    'wp-cron' => ['DOING_CRON', 'cron', 'cron'],
    'rest' => ['REST_REQUEST', 'rest', 'web'],
    'ajax' => ['DOING_AJAX', 'ajax', 'web'],
])->group('entry-point');

it('treats admin screens and everything else as web requests', function () {
    expect(EntryPoint::type())->toBe('web')
        ->and(EntryPoint::kind())->toBe('web');

    Functions\when('is_admin')->justReturn(true);

    expect(EntryPoint::type())->toBe('admin')
        ->and(EntryPoint::kind())->toBe('web');
})->group('entry-point');

it('ignores entry point constants that are defined as false', function () {
    $this->defineConstants(['WP_CLI' => false, 'DOING_CRON' => false, 'REST_REQUEST' => false]);

    expect(EntryPoint::isCli())->toBeFalse()
        ->and(EntryPoint::isCron())->toBeFalse()
        ->and(EntryPoint::isRest())->toBeFalse();
})->group('entry-point');

it('falls back to the site root when there is no request uri', function () {
    unset($_SERVER['REQUEST_URI']);
    expect(EntryPoint::requestUri())->toBe('/');

    $_SERVER['REQUEST_URI'] = '/shop?page=2';
    expect(EntryPoint::requestUri())->toBe('/shop?page=2');
})->group('entry-point');
