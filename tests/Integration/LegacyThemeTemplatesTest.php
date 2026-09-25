<?php
/**
 * A theme's template files named after the old taxonomies keep applying.
 *
 * WordPress matches a theme template by file name, and after 2.0.0 it asks for
 * taxonomy-blockendar_event_type. A theme still shipping taxonomy-event_type.html
 * would never match again, and the migration cannot rename a file. These tests
 * build a throwaway block theme carrying such files and prove the registrar
 * serves each under its new slug — and only the ones that are really ours.
 *
 * @package Blockendar\Tests
 */

declare( strict_types=1 );

namespace Blockendar\Tests\Integration;

// Building a throwaway theme is the point; see the file docblock.
// phpcs:disable WordPress.WP.AlternativeFunctions

use Blockendar\Blocks\TemplateRegistrar;
use WP_Block_Templates_Registry;
use WP_UnitTestCase;

class LegacyThemeTemplatesTest extends WP_UnitTestCase {

	private string $theme;
	private string $dir;
	private string $previous_theme;

	public function set_up(): void {
		parent::set_up();

		// A fresh directory per test: core's _get_block_templates_paths() keeps a
		// static per-directory cache of a theme's template files for the life
		// of the process, so a file written into a reused directory would never
		// be seen by the tests that follow.
		$this->theme          = 'blockendar-legacy-theme-' . wp_generate_password( 6, false );
		$this->previous_theme = get_stylesheet();
		$this->dir            = get_theme_root() . '/' . $this->theme;

		mkdir( $this->dir . '/templates', 0777, true );
		file_put_contents( $this->dir . '/style.css', "/*\nTheme Name: Blockendar Legacy Test\n*/\n" );
		file_put_contents( $this->dir . '/templates/index.html', '<!-- wp:paragraph --><p>index</p><!-- /wp:paragraph -->' );

		wp_clean_themes_cache();
		switch_theme( $this->theme );
		$this->assertSame( $this->theme, get_stylesheet(), 'precondition: the throwaway theme is active' );
	}

	public function tear_down(): void {
		switch_theme( $this->previous_theme );
		$this->remove_dir( $this->dir );
		wp_clean_themes_cache();
		$this->re_register();
		parent::tear_down();
	}

	private function remove_dir( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: [] as $path ) {
			is_dir( $path ) ? $this->remove_dir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Register afresh: the plugin registered at init, and the registry refuses a
	 * duplicate name, so drop what is there and let the registrar see the theme.
	 */
	private function re_register(): void {
		$registry = WP_Block_Templates_Registry::get_instance();

		foreach ( $registry->get_all_registered() as $name => $template ) {
			if ( str_starts_with( $name, 'blockendar//' ) ) {
				$registry->unregister( $name );
			}
		}

		( new TemplateRegistrar() )->register_templates();
	}

	private function theme_file( string $name, string $content ): void {
		file_put_contents( $this->dir . '/templates/' . $name, $content );
	}

	private function resolved( string $slug ): ?\WP_Block_Template {
		// Keyed by registry name for plugin templates, so not [0].
		$templates = array_values( get_block_templates( [ 'slug__in' => [ $slug ] ] ) );

		return $templates[0] ?? null;
	}

	public function test_an_old_named_theme_file_is_served_under_the_new_slug(): void {
		$content = '<!-- wp:paragraph --><p>the theme’s own type archive</p><!-- /wp:paragraph -->';
		$this->theme_file( 'taxonomy-event_type.html', $content );

		$this->re_register();

		$template = $this->resolved( 'taxonomy-blockendar_event_type' );
		$this->assertNotNull( $template );
		$this->assertSame( $content, $template->content, 'the theme file replaces the plugin default' );
		$this->assertSame( 'plugin', $template->source );
		$this->assertStringContainsString( 'templates/taxonomy-event_type.html', $template->description );
		$this->assertStringContainsString( 'taxonomy-blockendar_event_type.html', $template->description, 'it says what to rename the file to' );
	}

	public function test_per_term_files_and_other_taxonomies_are_carried_too(): void {
		$this->theme_file( 'taxonomy-event_type-concerts.html', '<!-- wp:paragraph --><p>concerts</p><!-- /wp:paragraph -->' );
		$this->theme_file( 'taxonomy-event_venue.html', '<!-- wp:paragraph --><p>venues</p><!-- /wp:paragraph -->' );

		$this->re_register();

		$this->assertStringContainsString( 'concerts', $this->resolved( 'taxonomy-blockendar_event_type-concerts' )->content );
		$this->assertStringContainsString( 'venues', $this->resolved( 'taxonomy-blockendar_event_venue' )->content );
	}

	public function test_a_file_that_merely_starts_with_an_old_name_is_left_alone(): void {
		$this->theme_file( 'taxonomy-event_typography.html', '<!-- wp:paragraph --><p>not ours</p><!-- /wp:paragraph -->' );

		$this->re_register();

		$this->assertFalse( WP_Block_Templates_Registry::get_instance()->is_registered( 'blockendar//taxonomy-blockendar_event_typography' ) );
	}

	public function test_a_theme_that_has_renamed_its_file_is_not_second_guessed(): void {
		$this->theme_file( 'taxonomy-event_type.html', '<!-- wp:paragraph --><p>stale</p><!-- /wp:paragraph -->' );
		$this->theme_file( 'taxonomy-blockendar_event_type.html', '<!-- wp:paragraph --><p>renamed</p><!-- /wp:paragraph -->' );

		$this->re_register();

		$template = $this->resolved( 'taxonomy-blockendar_event_type' );
		$this->assertSame( 'theme', $template->source, 'the theme file at the new name wins' );
		$this->assertStringContainsString( 'renamed', $template->content );

		$registered = WP_Block_Templates_Registry::get_instance()->get_registered( 'blockendar//taxonomy-blockendar_event_type' );
		$this->assertStringNotContainsString( 'stale', $registered->content, 'the plugin keeps its own default rather than the stale file' );
	}

	public function test_without_such_files_the_plugin_defaults_are_registered_as_before(): void {
		$this->re_register();

		$template = $this->resolved( 'taxonomy-blockendar_event_type' );
		$this->assertSame( 'plugin', $template->source );
		$this->assertSame( 'Event Type Archive', $template->title );
		$this->assertStringContainsString( 'wp:blockendar/calendar-view', $template->content );
	}
}
