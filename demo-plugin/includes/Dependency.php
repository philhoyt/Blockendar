<?php
/**
 * Blockendar dependency guard.
 *
 * @package BlockendarDemo
 */

declare( strict_types=1 );

namespace Blockendar\Demo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that a compatible Blockendar is present.
 *
 * This is deliberately not the `Requires Plugins:` header. That header resolves
 * against WordPress.org-formatted slugs, and Blockendar ships from GitHub with no
 * .org listing, so WordPress would report an uninstallable dependency and refuse
 * to activate this plugin outright.
 */
class Dependency {

	/**
	 * Transient holding the reason activation was refused.
	 */
	public const NOTICE_KEY = 'blockendar_demo_dependency_notice';

	/**
	 * Whether Blockendar is active and new enough to seed against.
	 */
	public static function is_satisfied(): bool {
		return '' === self::failure_reason();
	}

	/**
	 * Human-readable reason the dependency is unmet, or '' when satisfied.
	 */
	public static function failure_reason(): string {
		return self::evaluate( defined( 'BLOCKENDAR_VERSION' ) ? (string) BLOCKENDAR_VERSION : null );
	}

	/**
	 * The decision itself, separated from reading the constant so it can be
	 * tested against versions other than whatever is currently loaded.
	 *
	 * @param string|null $version Active Blockendar version, or null if absent.
	 */
	public static function evaluate( ?string $version ): string {
		if ( null === $version || '' === $version ) {
			return 'Blockendar Demo Content requires the Blockendar plugin to be installed and active.';
		}

		if ( version_compare( $version, BLOCKENDAR_DEMO_MIN_BLOCKENDAR, '<' ) ) {
			return sprintf(
				'Blockendar Demo Content requires Blockendar %1$s or newer. Version %2$s is active.',
				BLOCKENDAR_DEMO_MIN_BLOCKENDAR,
				$version
			);
		}

		return '';
	}

	/**
	 * Refuse activation: record the reason and deactivate self.
	 *
	 * Runs inside the activation hook, so the notice has to survive the redirect
	 * that follows. A transient does; an admin_notices callback registered here
	 * would not.
	 */
	public static function halt_activation(): void {
		set_transient( self::NOTICE_KEY, self::failure_reason(), MINUTE_IN_SECONDS );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( plugin_basename( BLOCKENDAR_DEMO_FILE ) );

		// Suppress the "Plugin activated." notice that would otherwise contradict us.
		unset( $_GET['activate'] );
	}

	/**
	 * Print the stored activation failure, once.
	 */
	public static function render_notice(): void {
		$reason = get_transient( self::NOTICE_KEY );

		if ( ! is_string( $reason ) || '' === $reason ) {
			return;
		}

		delete_transient( self::NOTICE_KEY );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $reason )
		);
	}
}
