<?php

use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Plugin;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('exposes the plugin and logging as global helpers', function () {
    $this->fakeCinder();

    cinder_log('Order placed', 'info', ['order' => 42]);

    expect(cinder())->toBe(Plugin::instance())
        ->and(Cinder::client()->logs()->pending())->toBe(1);
})->group('helpers');

it('leaves helpers alone when they are already defined', function () {
    require dirname(__DIR__, 2).'/src/functions.php';

    expect(function_exists('cinder'))->toBeTrue()
        ->and(function_exists('cinder_log'))->toBeTrue();
})->group('helpers');
