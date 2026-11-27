<?php

use Brain\Monkey\Functions;
use Cinderwatch\WordPress\MuPlugin\MuPluginLoader;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Functions\when('wp_mkdir_p')->alias(fn (string $directory): bool => mkdir($directory, 0777, true));
});

afterEach(function () {
    if (is_file(WPMU_PLUGIN_DIR.'/cinder-loader.php')) {
        unlink(WPMU_PLUGIN_DIR.'/cinder-loader.php');
    }

    if (is_dir(WPMU_PLUGIN_DIR)) {
        rmdir(WPMU_PLUGIN_DIR);
        rmdir(dirname(WPMU_PLUGIN_DIR));
    }
});

it('writes a loader that turns on SAVEQUERIES and requires the plugin', function () {
    $this->defineConstants(['CINDER_PLUGIN_FILE' => '/var/www/wp-content/plugins/cinder/cinder.php']);

    expect(MuPluginLoader::isInstalled())->toBeFalse()
        ->and(MuPluginLoader::install())->toBeTrue()
        ->and(MuPluginLoader::isInstalled())->toBeTrue();

    $contents = file_get_contents(WPMU_PLUGIN_DIR.'/cinder-loader.php');

    expect($contents)->toContain("define('SAVEQUERIES', true);")
        ->and($contents)->toContain("require_once '/var/www/wp-content/plugins/cinder/cinder.php';");
})->group('mu-plugin');

it('points the loader at the bundled plugin file when CINDER_PLUGIN_FILE is not defined', function () {
    MuPluginLoader::install();

    expect(file_get_contents(WPMU_PLUGIN_DIR.'/cinder-loader.php'))
        ->toContain("require_once '".dirname(__DIR__, 2)."/cinder.php';");
})->group('mu-plugin');

it('gives up when the mu-plugins directory cannot be created', function () {
    Functions\when('wp_mkdir_p')->justReturn(false);

    expect(MuPluginLoader::install())->toBeFalse();
})->group('mu-plugin');
