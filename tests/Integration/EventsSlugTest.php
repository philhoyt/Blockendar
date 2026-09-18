<?php
/**
 * Integration coverage for the events base slug setting.
 *
 * The slug is the rewrite base for single events and for the type, venue
 * and tag archives. It was stored but never read; these tests pin that the
 * post type and taxonomies register with it and that a change moves the
 * URLs after the flush the Upgrader performs.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

use Blockendar\Admin\SettingsPage;
use Blockendar\CPT\EventPostType;
use Blockendar\Taxonomy\EventTag;
use Blockendar\Taxonomy\EventType;
use Blockendar\Taxonomy\Venue;
use Blockendar\Upgrader;
use WP_UnitTestCase;

class EventsSlugTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		delete_option( SettingsPage::OPTION_NAME );
	}

	public function tear_down(): void {
		delete_option( SettingsPage::OPTION_NAME );
		$this->register_all();
		parent::tear_down();
	}

	/**
	 * Re-register the post type and taxonomies so they pick up the current slug,
	 * the way a fresh request would.
	 */
	private function register_all(): void {
		( new EventPostType() )->register_post_type();
		( new EventType() )->register_taxonomy();
		( new Venue() )->register_taxonomy();
		( new EventTag() )->register_taxonomy();
		( new EventType() )->add_rewrite_rules();
		( new Venue() )->add_rewrite_rules();
		( new EventTag() )->add_rewrite_rules();
	}

	public function test_the_default_slug_is_events(): void {
		$this->assertSame( 'events', SettingsPage::events_slug() );
	}

	public function test_an_empty_slug_falls_back_to_events(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => '' ] );
		$this->assertSame( 'events', SettingsPage::events_slug() );

		$sanitized = ( new SettingsPage() )->sanitize( [ 'events_slug' => '   ' ] );
		$this->assertSame( 'events', $sanitized['events_slug'] );
	}

	public function test_the_slug_is_sanitized(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => "What's On" ] );
		$this->assertSame( 'whats-on', SettingsPage::events_slug() );
	}

	public function test_a_custom_slug_moves_every_url(): void {
		update_option( SettingsPage::OPTION_NAME, [ 'events_slug' => 'whats-on' ] );
		$this->register_all();

		$post_type = get_post_type_object( EventPostType::POST_TYPE );
		$this->assertSame( 'whats-on', $post_type->rewrite['slug'] );
		$this->assertSame( 'whats-on', $post_type->has_archive );
		$this->assertSame( 'whats-on/type', get_taxonomy( EventType::TAXONOMY )->rewrite['slug'] );
		$this->assertSame( 'whats-on/venue', get_taxonomy( Venue::TAXONOMY )->rewrite['slug'] );
		$this->assertSame( 'whats-on/tag', get_taxonomy( EventTag::TAXONOMY )->rewrite['slug'] );

		update_option( Upgrader::VERSION_OPTION, 'stale' );
		( new Upgrader() )->maybe_upgrade();

		$rules = get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( '^whats-on/type/([^/]+)/?$', $rules );
		$this->assertArrayHasKey( '^whats-on/venue/([^/]+)/?$', $rules );
		$this->assertArrayHasKey( '^whats-on/tag/([^/]+)/?$', $rules );
		// (The previous slug's explicit rules linger in this process because
		// add_rewrite_rule() accumulates; a real request registers once.)

		$event = self::factory()->post->create(
			[
				'post_type'   => EventPostType::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => 'gala',
			]
		);
		$this->assertSame( home_url( '/whats-on/gala/' ), get_permalink( $event ) );
		$this->assertSame( home_url( '/whats-on/' ), get_post_type_archive_link( EventPostType::POST_TYPE ) );
	}
}
