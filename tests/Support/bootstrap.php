<?php

require_once __DIR__.'/functions.php';
require_once __DIR__.'/../Fixtures/WP_CLI.php';
require_once __DIR__.'/../Fixtures/WP_CLI/ExitException.php';
require_once __DIR__.'/../Fixtures/WP_Error.php';
require_once __DIR__.'/../Fixtures/WP_REST_Response.php';
require_once __DIR__.'/../Fixtures/WP_User.php';

define('ABSPATH', dirname(__DIR__).'/Fixtures/wordpress/');
define('WPMU_PLUGIN_DIR', sys_get_temp_dir().'/cinder-wordpress-tests-'.getmypid().'/mu-plugins');
