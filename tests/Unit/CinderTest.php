<?php

use Cinderwatch\Health\Observation;
use Cinderwatch\Health\Probe;
use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Plugin;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

it('reports throwables through the plugin client', function () {
    $transport = $this->fakeCinder();

    $report = Cinder::report(new RuntimeException('boom'), ['order' => 42], handled: true, fingerprint: ['checkout']);

    expect($report)->toBeArray()
        ->and($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]['url'])->toEndWith('/api/v1/errors')
        ->and($transport->sent[0]['payload'])->toBe($report)
        ->and(Cinder::client())->toBe(Plugin::instance()->manager()->client());
})->group('cinder');

it('writes logs only once a key is configured', function () {
    $this->fakeCinder('');
    Cinder::log('ignored');

    expect(Cinder::client()->logs()->pending())->toBe(0);

    $this->fakeCinder();
    cinder_log('Payment failed', 'error', ['order' => 42]);

    expect(Cinder::client()->logs()->pending())->toBe(1);
})->group('cinder');

it('replaces the health probes', function () {
    $this->fakeCinder();

    $probe = new class implements Probe
    {
        public function id(): string
        {
            return 'custom';
        }

        public function title(): string
        {
            return 'Custom';
        }

        public function examine(): Observation
        {
            return Observation::healthy('Fine');
        }
    };

    Cinder::probes([$probe]);

    expect(Cinder::health()->probes())->toBe([$probe])
        ->and(Cinder::health()->inspect()->findings[0]->observation->summary)->toBe('Fine');
})->group('cinder');

it('filters and groups reports through the manager', function () {
    $transport = $this->fakeCinder();
    $manager = Plugin::instance()->manager();

    $manager->reportOnlyWhen(fn (Throwable $throwable): bool => $throwable instanceof LogicException);
    $manager->fingerprintUsing(fn (): array => ['grouped']);

    expect($manager->report(new RuntimeException('skipped')))->toBeNull()
        ->and($manager->report(new LogicException('sent')))->toBeArray()
        ->and($transport->sent)->toHaveCount(1)
        ->and(json_encode($transport->sent[0]['payload']))->toContain('grouped');
})->group('cinder');
