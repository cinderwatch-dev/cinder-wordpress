<?php

use Brain\Monkey\Functions;
use Cinderwatch\WordPress\Admin\SettingsPage;
use Cinderwatch\WordPress\Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Functions\stubs([
        'current_user_can' => true,
        'check_admin_referer' => true,
        'esc_attr' => fn (string $text): string => htmlspecialchars($text, ENT_QUOTES),
        'esc_html' => fn (string $text): string => htmlspecialchars($text, ENT_QUOTES),
        'disabled' => function (bool $disabled): void {
            echo $disabled ? ' disabled="disabled"' : '';
        },
        'checked' => function (bool $checked): void {
            echo $checked ? ' checked="checked"' : '';
        },
        'settings_fields' => function (string $group): void {
            echo '<input type="hidden" name="option_page" value="'.$group.'" />';
        },
        'submit_button' => function (): void {
            echo '<input type="submit" />';
        },
        'wp_nonce_field' => function (string $action): void {
            echo '<input type="hidden" name="'.$action.'_nonce" />';
        },
    ]);
});

function renderSettingsPage(): string
{
    ob_start();
    (new SettingsPage)->render();

    return (string) ob_get_clean();
}

it('adds the page under settings and registers the option', function () {
    $page = [];
    $setting = [];
    Functions\when('add_options_page')->alias(function (...$arguments) use (&$page): void {
        $page = $arguments;
    });
    Functions\when('register_setting')->alias(function (...$arguments) use (&$setting): void {
        $setting = $arguments;
    });

    (new SettingsPage)->register();
    $this->doAction('admin_init');

    expect(array_slice($page, 0, 4))->toBe(['Cinder', 'Cinder', 'manage_options', 'cinder'])
        ->and($page[4])->toBeCallable()
        ->and($setting[0])->toBe('cinder')
        ->and($setting[1])->toBe('cinder_settings')
        ->and($setting[2]['type'])->toBe('array')
        ->and($setting[2]['sanitize_callback']([]))->toBe([]);
})->group('settings');

it('merges saved settings into the existing ones', function () {
    $this->settings(['key' => 'saved-key', 'report_logs' => '1']);

    expect((new SettingsPage)->sanitizeOptions(['report_logs' => '0']))->toBe(['key' => 'saved-key', 'report_logs' => '0'])
        ->and((new SettingsPage)->sanitizeOptions(null))->toBe(['key' => 'saved-key', 'report_logs' => '1']);
})->group('settings');

it('keeps the key out of the option when wp-config defines it', function () {
    $this->wpOptions['cinder_settings'] = 'corrupt';
    $this->defineConstants(['CINDER_KEY' => 'constant-key']);

    expect((new SettingsPage)->sanitizeOptions(['key' => 'typed-key', 'report_errors' => '1']))->toBe(['report_errors' => '1']);
})->group('settings');

it('renders the settings form with the saved values', function () {
    $this->settings([
        'key' => 'saved-key',
        'base_url' => 'https://cinder.test',
        'report_errors' => '1',
        'report_logs' => '0',
        'report_traces' => '1',
        'health_endpoint_enabled' => '1',
        'health_check_secret' => 's3cret',
    ]);

    $html = renderSettingsPage();

    expect($html)->toContain('name="cinder_settings[key]"')
        ->and($html)->toContain('value="saved-key"')
        ->and($html)->toContain('value="https://cinder.test"')
        ->and($html)->toContain('value="s3cret"')
        ->and($html)->toContain('name="cinder_settings[report_logs]"')
        ->and(substr_count($html, 'checked="checked"'))->toBe(3)
        ->and($html)->toContain('<code>/cinder/v1/health</code>')
        ->and($html)->toContain('Send test error')
        ->and($html)->toContain('Install mu-plugin loader')
        ->and($html)->not->toContain('disabled="disabled"');
})->group('settings');

it('locks fields that wp-config or the environment already set', function () {
    $this->wpOptions['cinder_settings'] = 'corrupt';
    $this->defineConstants(['CINDER_KEY' => 'constant-key', 'CINDER_URL' => 'https://cinder.test']);
    $this->setEnvironment(['CINDER_HEALTH_CHECK_SECRET' => 'secret']);

    $html = renderSettingsPage();

    expect(substr_count($html, 'disabled="disabled"'))->toBe(3)
        ->and($html)->toContain('Defined via constant or environment.')
        ->and($html)->toContain('value="https://cinderwatch.dev"');
})->group('settings');

it('shows nothing to users who cannot manage options', function () {
    Functions\when('current_user_can')->justReturn(false);

    expect(renderSettingsPage())->toBe('');
})->group('settings');

it('sends a test error from the settings page', function () {
    $transport = $this->fakeCinder();
    $_POST['cinder_test_error'] = '1';

    expect(renderSettingsPage())->toContain('Test exception sent.')
        ->and($transport->sent)->toHaveCount(1)
        ->and(json_encode($transport->sent[0]['payload']))->toContain('Cinder test exception from Test site');
})->group('settings');

it('installs the mu-plugin loader from the settings page', function (bool $installed, string $message) {
    $_POST['cinder_install_loader'] = '1';
    Functions\when('wp_mkdir_p')->justReturn(false);

    if ($installed) {
        Functions\when('wp_mkdir_p')->alias(fn (string $directory): bool => mkdir($directory, 0777, true));
    }

    expect(renderSettingsPage())->toContain($message);

    if ($installed) {
        unlink(WPMU_PLUGIN_DIR.'/cinder-loader.php');
        rmdir(WPMU_PLUGIN_DIR);
        rmdir(dirname(WPMU_PLUGIN_DIR));
    }
})->with([
    'installed' => [true, 'Must-use loader installed.'],
    'failed' => [false, 'Could not install loader.'],
])->group('settings');
