<?php
/**
 * PHPUnit bootstrap for integration tests.
 *
 * Unlike tests/bootstrap.php — which stubs WordPress with Brain Monkey and never
 * loads core — this boots the real WordPress test suite so tests can exercise
 * capabilities, REST routes and the database.
 *
 * Runs inside wp-env's tests-cli container, where WP_TESTS_DIR points at the
 * WordPress PHPUnit library:
 *
 *   npm run test:integration
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

$blockendar_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $blockendar_tests_dir ) {
	$blockendar_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $blockendar_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite at {$blockendar_tests_dir}.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo "Run these tests through wp-env: npm run test:integration\n";
	exit( 1 );
}

// The WP test suite requires the Yoast polyfills; point it at our vendored copy.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $blockendar_tests_dir . '/includes/functions.php';

/**
 * Load the plugin before WordPress finishes booting, and create its custom
 * tables — the activation hook does not fire in the test environment.
 */
tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/blockendar.php';
	}
);

tests_add_filter(
	'init',
	static function (): void {
		\Blockendar\DB\Schema::create_tables();
	},
	5
);

require $blockendar_tests_dir . '/includes/bootstrap.php';
