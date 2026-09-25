<?php
/**
 * The taxonomy migration is wired into the plugin's lifecycle.
 *
 * TaxonomyPrefixMigrationTest proves the engine; this proves the plumbing
 * around it: the init hook that fires it, the notice and hold when it refuses,
 * the activation gate that keeps a fresh install from ever running it, and the
 * uninstall that removes what it left behind.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\CPT\EventPostType;
use Blockendar\DB\Schema;
use Blockendar\Migration\TaxonomyPrefixMigration;
use Blockendar\Taxonomy\EventType;
use Blockendar\Upgrader;
use WP_UnitTestCase;

require_once __DIR__ . '/LegacyTaxonomyFixtures.php';

class TaxonomyPrefixWiringTest extends WP_UnitTestCase {

	use LegacyTaxonomyFixtures;

	private TaxonomyPrefixMigration $migration;

	public function set_up(): void {
		parent::set_up();
		$this->migration = new TaxonomyPrefixMigration();
		_set_cron_array( [] );
		delete_option( TaxonomyPrefixMigration::GATE_OPTION );
		delete_option( TaxonomyPrefixMigration::LOG_OPTION );
		delete_option( TaxonomyPrefixMigration::CURSOR_OPTION );
		delete_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT );
	}

	/**
	 * Whether a hook has a TaxonomyPrefixMigration method attached at a priority.
	 */
	private function migration_hooked( string $hook, string $method, int $priority ): bool {
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks[ $priority ] ?? [] as $callback ) {
			$fn = $callback['function'];
			if ( is_array( $fn ) && $fn[0] instanceof TaxonomyPrefixMigration && $method === $fn[1] ) {
				return true;
			}
		}

		return false;
	}

	private function taxonomy_of( int $term_taxonomy_id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $term_taxonomy_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_the_plugin_attaches_the_migration_to_init_30_and_admin_notices(): void {
		$this->assertTrue( $this->migration_hooked( 'init', 'maybe_run_on_init', 30 ), 'init 30: after the taxonomies register at 10' );
		$this->assertTrue( $this->migration_hooked( 'admin_notices', 'render_blocked_notice', 10 ) );
		$this->assertFalse( $this->migration_hooked( 'init', 'maybe_run_on_init', 10 ), 'not before the taxonomies exist' );
	}

	public function test_init_migrates_a_site_once_and_then_leaves_it_alone(): void {
		$type = $this->seed_legacy_term( 'event_type', 'Concerts' );

		$this->migration->maybe_run_on_init();

		$this->assertSame( 'blockendar_event_type', $this->taxonomy_of( $type['term_taxonomy_id'] ) );
		$this->assertFalse( $this->migration->needs_migration(), 'the gate is set' );
		$this->assertSame( 'yes', $this->gate_autoload(), 'the gate is read on every request, so it is autoloaded' );

		// A term created afterwards under the old name — another plugin's — is
		// not touched by later requests.
		$theirs = $this->seed_legacy_term( 'event_type', 'Theirs' );
		$this->migration->maybe_run_on_init();
		$this->assertSame( 'event_type', $this->taxonomy_of( $theirs['term_taxonomy_id'] ) );
	}

	private function gate_autoload(): string {
		global $wpdb;

		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", TaxonomyPrefixMigration::GATE_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// 6.6+ writes 'on'/'off' (and 'auto-on'); older sites 'yes'/'no'.
		return in_array( $autoload, [ 'yes', 'on', 'auto-on' ], true ) ? 'yes' : $autoload;
	}

	public function test_a_refused_run_leaves_a_notice_and_holds_for_an_hour(): void {
		$legacy = $this->seed_legacy_term( 'event_type', 'Concerts' );
		// Rows already at the target name: preflight refuses with target_occupied.
		$occupant = wp_insert_term( 'Occupant', EventType::TAXONOMY );

		$this->migration->maybe_run_on_init();

		$this->assertSame( 'event_type', $this->taxonomy_of( $legacy['term_taxonomy_id'] ), 'nothing moved' );
		$this->assertTrue( $this->migration->needs_migration(), 'the gate stays unset for a retry' );
		$blocked = get_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT );
		$this->assertSame( 'target_occupied', $blocked['code'] );

		// Only administrators see why.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', get_echo( [ $this->migration, 'render_blocked_notice' ] ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$notice = get_echo( [ $this->migration, 'render_blocked_notice' ] );
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( esc_html( $blocked['message'] ), $notice );
		$this->assertStringContainsString( 'migrate-taxonomies', $notice );

		// Clearing the conflict is not enough on its own: init holds while the
		// record stands, so a blocked site is not re-preflighted on every request.
		wp_delete_term( $occupant['term_id'], EventType::TAXONOMY );
		$this->migration->maybe_run_on_init();
		$this->assertSame( 'event_type', $this->taxonomy_of( $legacy['term_taxonomy_id'] ), 'held' );

		// Once the hold lapses (or --run asks) it migrates, and the notice goes.
		delete_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT );
		set_transient(
			TaxonomyPrefixMigration::BLOCKED_TRANSIENT,
			[
				'code'    => 'x',
				'message' => 'x',
			],
			HOUR_IN_SECONDS
		);
		$this->assertTrue( $this->migration->run() );
		$this->assertSame( 'blockendar_event_type', $this->taxonomy_of( $legacy['term_taxonomy_id'] ) );
		$this->assertFalse( get_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT ), 'a successful run clears the record' );
		$this->assertSame( '', get_echo( [ $this->migration, 'render_blocked_notice' ] ) );
	}

	public function test_activation_gates_a_fresh_install_but_not_a_site_that_has_run_before(): void {
		$hook = 'activate_' . plugin_basename( BLOCKENDAR_FILE );
		$this->assertTrue( has_action( $hook ) > 0, 'precondition: the activation hook is registered' );

		// Fresh: never ran, no events.
		delete_option( Upgrader::VERSION_OPTION );
		$this->assertTrue( $this->migration->is_fresh_install() );
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->assertFalse( $this->migration->needs_migration(), 'a fresh install is gated and never migrates' );

		// A 1.x site re-activated by hand: the version option says it has run.
		delete_option( TaxonomyPrefixMigration::GATE_OPTION );
		update_option( Upgrader::VERSION_OPTION, '1.8.2' );
		$this->assertFalse( $this->migration->is_fresh_install() );
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->assertTrue( $this->migration->needs_migration(), 'left for init to migrate' );

		// An older 1.x without the version option, but with events.
		delete_option( Upgrader::VERSION_OPTION );
		self::factory()->post->create( [ 'post_type' => EventPostType::POST_TYPE ] );
		$this->assertFalse( $this->migration->is_fresh_install() );
		do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->assertTrue( $this->migration->needs_migration() );
	}

	public function test_uninstall_removes_the_migration_state(): void {
		$post = self::factory()->post->create();
		update_option( TaxonomyPrefixMigration::GATE_OPTION, '2.0.0' );
		update_option( TaxonomyPrefixMigration::LOG_OPTION, [ 'x' ], false );
		update_option( TaxonomyPrefixMigration::CURSOR_OPTION, 5, false );
		set_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT, [ 'code' => 'x' ], HOUR_IN_SECONDS );
		add_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, 'original' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( BLOCKENDAR_FILE ) );
		}
		require_once BLOCKENDAR_DIR . 'uninstall.php';

		$this->assertFalse( get_option( TaxonomyPrefixMigration::GATE_OPTION ) );
		$this->assertFalse( get_option( TaxonomyPrefixMigration::LOG_OPTION ) );
		$this->assertFalse( get_option( TaxonomyPrefixMigration::CURSOR_OPTION ) );
		$this->assertFalse( get_transient( TaxonomyPrefixMigration::BLOCKED_TRANSIENT ) );
		$this->assertSame( '', get_post_meta( $post, TaxonomyPrefixMigration::BACKUP_META, true ) );

		// uninstall.php dropped the plugin tables; put them back for the tests that follow.
		Schema::create_tables();
	}
}
