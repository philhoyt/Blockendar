<?php
/**
 * Guards the precondition the integration suite's error checks rest on.
 *
 * PHPUnit turns a PHP notice, warning or deprecation raised inside a test into
 * a failure only while two things hold: error_reporting() includes the level —
 * the tests site runs WP_DEBUG on (.wp-env.json, env.tests.config), so core
 * sets E_ALL in wp_debug_mode() — and PHPUnit's own handler is the one
 * registered. ErrorHandler::register() in PHPUnit 9.6 declines silently when
 * set_error_handler() returns a previous handler, so the day something in the
 * plugin, the demo plugin or a test registers one, conversion stops and nothing
 * says so. These tests say so.
 *
 * Deprecations are converted only because phpunit-integration.xml sets
 * convertDeprecationsToExceptions="true"; PHPUnit 9 defaults that to false.
 *
 * The converted errors are caught with a plain try/catch. PHPUnit 9.6 emits a
 * "deprecated and will no longer be possible in PHPUnit 10" warning from both
 * expectNotice() and expectException( Notice::class ), and a warning does not
 * fail the run — it would just be permanent noise in every report.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use PHPUnit\Framework\Error\Deprecated;
use PHPUnit\Framework\Error\Notice;
use WP_UnitTestCase;

class ErrorConversionTest extends WP_UnitTestCase {

	public function test_error_reporting_is_unmasked(): void {
		// Read, not set: the point is to notice if the tests site stops running
		// with WP_DEBUG on, which is what un-masks these levels in the first place.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		$level = error_reporting();

		$this->assertSame(
			E_ALL,
			$level,
			'The tests site should run with WP_DEBUG on so core sets E_ALL; see env.tests.config in .wp-env.json.'
		);
	}

	public function test_a_user_notice_inside_a_test_is_a_failure(): void {
		try {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error( 'guard', E_USER_NOTICE );
		} catch ( Notice $converted ) {
			$this->assertStringContainsString( 'guard', $converted->getMessage() );
			return;
		}

		$this->fail( 'E_USER_NOTICE was not converted to an exception — is another error handler registered, or E_NOTICE masked?' );
	}

	public function test_a_deprecation_inside_a_test_is_a_failure(): void {
		try {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error( 'guard', E_USER_DEPRECATED );
		} catch ( Deprecated $converted ) {
			$this->assertStringContainsString( 'guard', $converted->getMessage() );
			return;
		}

		$this->fail( 'E_USER_DEPRECATED was not converted to an exception — is convertDeprecationsToExceptions still "true" in phpunit-integration.xml?' );
	}
}
