<?php

use Brain\Monkey\Functions;
use Cinderwatch\WordPress\Errors\WordPressContextEnricher;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('describes the web request and the logged in user', function () {
    $_SERVER['REQUEST_URI'] = '/shop/cart?coupon=x';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    Functions\when('wp_get_current_user')->justReturn(new WP_User(7, 'jane@example.test'));

    $attributes = (new WordPressContextEnricher)->attributesFor(new RuntimeException('boom'));

    expect($attributes)->toBe([
        'wordpress.theme' => 'twentytwentyfive',
        'cinder.entry.kind' => 'web',
        'wordpress.request_type' => 'web',
        'cinder.entry.name' => '/shop/cart',
        'http.request.method' => 'POST',
        'url.full' => 'https://example.test/shop/cart?coupon=x',
        'url.path' => '/shop/cart?coupon=x',
        'user_agent.original' => 'Mozilla/5.0',
        'user.id' => '7',
        'user.email' => 'jane@example.test',
    ]);
})->group('errors');

it('leaves out the user when nobody is logged in or pluggable functions are not loaded yet', function () {
    unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_USER_AGENT']);

    $loggedOut = (new WordPressContextEnricher)->attributesFor(new RuntimeException('boom'));

    $this->withoutFunctions('wp_get_current_user');
    $tooEarly = (new WordPressContextEnricher)->attributesFor(new RuntimeException('boom'));

    expect($loggedOut)->not->toHaveKeys(['user.id', 'http.request.method', 'user_agent.original'])
        ->and($tooEarly)->not->toHaveKey('user.id');
})->group('errors');

it('names wp-cron runs and adds the blog id on multisite', function () {
    $this->defineConstants(['DOING_CRON' => true]);
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_current_blog_id')->justReturn(3);

    $attributes = (new WordPressContextEnricher)->attributesFor(new RuntimeException('boom'));

    expect($attributes)->toMatchArray([
        'wordpress.blog_id' => '3',
        'cinder.entry.kind' => 'cron',
        'cinder.entry.name' => 'wp-cron',
    ]);
})->group('errors');

it('leaves request details out of wp-cli errors', function () {
    $this->defineConstants(['WP_CLI' => true]);

    $attributes = (new WordPressContextEnricher)->attributesFor(new RuntimeException('boom'));

    expect($attributes)->toBe([
        'wordpress.theme' => 'twentytwentyfive',
        'cinder.entry.kind' => 'cli',
        'wordpress.request_type' => 'cli',
    ]);
})->group('errors');

it('blames the plugin found in the stack before any theme', function () {
    $exception = new ErrorException('boom', 0, E_WARNING, '/var/www/wp-content/themes/storefront/functions.php', 12);
    $fromPlugin = (fn (): ErrorException => new ErrorException('boom', 0, E_WARNING, '/var/www/wp-content/plugins/acme-shop/includes/cart.php', 12))();

    $enricher = new WordPressContextEnricher;

    expect($enricher->attributesFor($exception)['wordpress.plugin'])->toBe('storefront')
        ->and($enricher->attributesFor($fromPlugin)['wordpress.plugin'])->toBe('acme-shop')
        ->and($enricher->attributesFor(new RuntimeException('boom')))->not->toHaveKey('wordpress.plugin');
})->group('errors');

it('blames a plugin further down the stack trace', function () {
    $exception = new RuntimeException('boom');
    (new ReflectionProperty(Exception::class, 'trace'))->setValue($exception, [
        ['function' => 'do_action'],
        ['file' => '/var/www/wp-content/plugins/acme-shop/acme-shop.php', 'line' => 3],
    ]);

    expect((new WordPressContextEnricher)->attributesFor($exception)['wordpress.plugin'])->toBe('acme-shop');
})->group('errors');
