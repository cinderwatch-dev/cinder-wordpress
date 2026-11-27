<?php

use Cinderwatch\Config\CinderConfig;
use Cinderwatch\Support\Time;
use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Tests\TestCase;
use Cinderwatch\WordPress\Tracing\QueryCollector;

uses(TestCase::class);

beforeEach(function () {
    $this->defineConstants(['SAVEQUERIES' => true]);
    $GLOBALS['wpdb'] = (object) ['queries' => [
        ["SELECT option_value\n  FROM wp_options WHERE option_name = 'siteurl'", 0.0012, 'get_option', 1_700_000_000.5],
        ['SELECT * FROM wp_posts WHERE ID IN ('.implode(',', range(1, 60)).')', 0.002, 'WP_Query->get_posts'],
        ['not', 'a valid entry'],
        'garbage',
    ]];
});

it('records the saved queries as spans inside the request trace', function () {
    $transport = $this->fakeCinder();
    $root = Cinder::client()->tracer()->open('Request - /');

    (new QueryCollector)->register();
    do_action('shutdown');
    Cinder::client()->tracer()->close($root);

    $spans = $this->exportedSpans($transport);

    expect($spans)->toHaveCount(4)
        ->and($spans[0]['name'])->toBe("Query - SELECT option_value FROM wp_options WHERE option_name = 'siteurl'")
        ->and($spans[0]['parent'])->toBe($root->spanId)
        ->and($spans[0]['attributes'])->toBe([
            'cinder.span.kind' => 'query',
            'db.query.text' => "SELECT option_value\n  FROM wp_options WHERE option_name = 'siteurl'",
            'db.system.name' => 'mysql',
            'wordpress.db.caller' => 'get_option',
        ])
        ->and(strlen($spans[1]['name']))->toBe(strlen('Query - ') + 120)
        ->and($spans[1]['name'])->toEndWith('...')
        ->and($spans[2]['name'])->toBe('Query - not')
        ->and($spans[2]['attributes']['wordpress.db.caller'])->toBe('');
})->group('tracing');

it('uses the recorded start time and duration of each query', function () {
    $this->fakeCinder();
    $tracer = Cinder::client()->tracer();
    $tracer->open('Request - /');

    (new QueryCollector)->recordQueries();

    $queue = (new ReflectionProperty($tracer, 'exportQueue'))->getValue($tracer);

    expect($queue[0]->startedAt())->toBe(Time::unixNano(1_700_000_000.5))
        ->and($queue[0]->endedAt() - $queue[0]->startedAt())->toBe(1_200_000);
})->group('tracing');

it('records nothing outside a trace or without SAVEQUERIES', function () {
    $transport = $this->fakeCinder();
    $collector = new QueryCollector;

    $collector->recordQueries();

    $this->defineConstants(['SAVEQUERIES' => false]);
    Cinder::client()->tracer()->open('Request - /');
    $collector->recordQueries();

    $this->defineConstants(['SAVEQUERIES' => true]);
    $GLOBALS['wpdb'] = (object) [];
    $collector->recordQueries();

    expect($this->exportedSpans($transport))->toBe([]);
})->group('tracing');

it('does not listen for queries when tracing is off', function () {
    $this->fakeCinder(new CinderConfig(key: 'project-key', reportTraces: false));

    (new QueryCollector)->register();

    expect($this->hasHook('shutdown'))->toBeFalse();
})->group('tracing');
