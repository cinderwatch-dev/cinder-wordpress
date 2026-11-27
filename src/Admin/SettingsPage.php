<?php

namespace Cinderwatch\WordPress\Admin;

use Cinderwatch\WordPress\Cinder;
use Cinderwatch\WordPress\Config\WordPressConfigResolver;
use Cinderwatch\WordPress\MuPlugin\MuPluginLoader;
use Exception;

class SettingsPage
{
    private const OPTION_KEY = 'cinder_settings';

    public function register(): void
    {
        add_options_page(
            'Cinder',
            'Cinder',
            'manage_options',
            'cinder',
            $this->render(...),
        );

        add_action('admin_init', $this->registerSettings(...));
    }

    public function registerSettings(): void
    {
        register_setting('cinder', self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => $this->sanitizeOptions(...),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>
     */
    public function sanitizeOptions(?array $input): array
    {
        $existing = get_option(self::OPTION_KEY, []);
        $existing = is_array($existing) ? $existing : [];
        $input ??= [];

        if (WordPressConfigResolver::isLocked('CINDER_KEY')) {
            unset($input['key']);
        }

        return array_merge($existing, $input);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['cinder_test_error']) && check_admin_referer('cinder_test_error')) {
            Cinder::report(new Exception('Cinder test exception from '.get_bloginfo('name')));
            echo '<div class="notice notice-success"><p>Test exception sent.</p></div>';
        }

        if (isset($_POST['cinder_install_loader']) && check_admin_referer('cinder_install_loader')) {
            $message = MuPluginLoader::install()
                ? 'Must-use loader installed.'
                : 'Could not install loader.';

            echo '<div class="notice notice-info"><p>'.esc_html($message).'</p></div>';
        }

        $options = get_option(self::OPTION_KEY, []);
        $options = is_array($options) ? $options : [];

        ?>
        <div class="wrap">
            <h1>Cinder</h1>
            <form method="post" action="options.php">
                <?php settings_fields('cinder'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cinder_key">API key</label></th>
                        <td>
                            <input
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[key]"
                                id="cinder_key"
                                type="password"
                                class="regular-text"
                                value="<?php echo esc_attr((string) ($options['key'] ?? '')); ?>"
                                <?php disabled(WordPressConfigResolver::isLocked('CINDER_KEY')); ?>
                            />
                            <?php if (WordPressConfigResolver::isLocked('CINDER_KEY')) { ?>
                                <p class="description">Defined via constant or environment.</p>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cinder_base_url">Base URL</label></th>
                        <td>
                            <input
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[base_url]"
                                id="cinder_base_url"
                                type="url"
                                class="regular-text"
                                value="<?php echo esc_attr((string) ($options['base_url'] ?? 'https://cinderwatch.dev')); ?>"
                                <?php disabled(WordPressConfigResolver::isLocked('CINDER_URL')); ?>
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Reporting</th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[report_errors]" value="0" />
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[report_errors]"
                                    value="1"
                                    <?php checked((bool) ($options['report_errors'] ?? true)); ?>
                                />
                                Report errors
                            </label>
                            <br />
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[report_logs]" value="0" />
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[report_logs]"
                                    value="1"
                                    <?php checked((bool) ($options['report_logs'] ?? true)); ?>
                                />
                                Report logs
                            </label>
                            <br />
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[report_traces]" value="0" />
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[report_traces]"
                                    value="1"
                                    <?php checked((bool) ($options['report_traces'] ?? true)); ?>
                                />
                                Report traces
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Health endpoint</th>
                        <td>
                            <label>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[health_endpoint_enabled]" value="0" />
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[health_endpoint_enabled]"
                                    value="1"
                                    <?php checked((bool) ($options['health_endpoint_enabled'] ?? false)); ?>
                                />
                                Enable <code>/cinder/v1/health</code>
                            </label>
                            <p>
                                <label for="cinder_health_secret">Secret</label><br />
                                <input
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[health_check_secret]"
                                    id="cinder_health_secret"
                                    type="password"
                                    class="regular-text"
                                    value="<?php echo esc_attr((string) ($options['health_check_secret'] ?? '')); ?>"
                                    <?php disabled(WordPressConfigResolver::isLocked('CINDER_HEALTH_CHECK_SECRET')); ?>
                                />
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post" class="cinder-actions">
                <?php wp_nonce_field('cinder_test_error'); ?>
                <button type="submit" name="cinder_test_error" value="1" class="button">Send test error</button>
            </form>
            <form method="post" class="cinder-actions">
                <?php wp_nonce_field('cinder_install_loader'); ?>
                <button type="submit" name="cinder_install_loader" value="1" class="button">
                    Install mu-plugin loader
                </button>
            </form>
        </div>
        <?php
    }
}
