<?php
/**
 * Render-time support for block markup that still names the old taxonomies.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Block_Template;

/**
 * Keeps blocks working whose attributes name a pre-2.0.0 taxonomy.
 *
 * The migration rewrites block markup stored in the database. It cannot reach
 * markup that lives in files — a theme's `single-blockendar_event.html` with
 * `<!-- wp:post-terms {"term":"event_type"} /-->`, a template part, a pattern —
 * nor a revision restored after the fact. Rendered as written, such a block
 * asks for a taxonomy nothing registers any more and outputs nothing, so an
 * event page quietly loses its type label.
 *
 * So the same seven attribute rewrites the sweep applies are applied again at
 * render time, to every block from every source, through `render_block_data`
 * (which core runs for inner blocks too) and, for the Query Loop's `taxQuery`,
 * through the context it passes down. Theme-sourced templates and parts
 * get the same treatment on the way to the Site Editor, so what an editor
 * sees matches what renders. Database content is left as it is: the sweep
 * already moved it, and rewriting it here would only hide a revision that
 * brought an old name back.
 */
class LegacyBlockAttributes {

	private TaxonomyPrefixMigration $migration;

	public function __construct() {
		$this->migration = new TaxonomyPrefixMigration();
	}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_filter( 'render_block_data', [ $this, 'rewrite_for_render' ] );
		add_filter( 'render_block_context', [ $this, 'rewrite_context' ] );
		add_filter( 'get_block_templates', [ $this, 'rewrite_templates' ] );
		add_filter( 'get_block_template', [ $this, 'rewrite_template' ] );
		add_filter( 'get_block_file_template', [ $this, 'rewrite_template' ] );
	}

	/**
	 * Rename the taxonomy references in a block about to render.
	 *
	 * @param array<string, mixed> $parsed_block The block, as parse_blocks() shaped it.
	 * @return array<string, mixed>
	 */
	public function rewrite_for_render( $parsed_block ) {
		if ( ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		$attrs = $this->migration->rewrite_block_attributes(
			(string) ( $parsed_block['blockName'] ?? '' ),
			is_array( $parsed_block['attrs'] ?? null ) ? $parsed_block['attrs'] : []
		);

		if ( null !== $attrs ) {
			$parsed_block['attrs'] = $attrs;
		}

		return $parsed_block;
	}

	/**
	 * Rename the taxonomy references in the `query` context a Query Loop hands
	 * its Post Template.
	 *
	 * render_block_data alone is not enough for a nested Query Loop: core builds
	 * an inner block, and the context it provides to its children, from the
	 * original attributes before that filter runs, and the refresh afterwards
	 * keeps that context. The Post Template reads `taxQuery` from the context,
	 * so it is renamed there as well.
	 *
	 * @param array<string, mixed> $context The block's context.
	 * @return array<string, mixed>
	 */
	public function rewrite_context( $context ) {
		if ( ! is_array( $context ) || ! isset( $context['query'] ) || ! is_array( $context['query'] ) ) {
			return $context;
		}

		$attrs = $this->migration->rewrite_block_attributes( 'core/query', [ 'query' => $context['query'] ] );

		if ( null !== $attrs ) {
			$context['query'] = $attrs['query'];
		}

		return $context;
	}

	/**
	 * Rewrite every theme-sourced template in a query result.
	 *
	 * @param mixed $templates Templates, as get_block_templates() found them.
	 * @return mixed
	 */
	public function rewrite_templates( $templates ) {
		if ( ! is_array( $templates ) ) {
			return $templates;
		}

		foreach ( $templates as $key => $template ) {
			$templates[ $key ] = $this->rewrite_template( $template );
		}

		return $templates;
	}

	/**
	 * Rewrite one template when it comes from a theme file.
	 *
	 * @param mixed $template A template, or whatever the filter was handed.
	 * @return mixed
	 */
	public function rewrite_template( $template ) {
		if ( ! $template instanceof WP_Block_Template || 'theme' !== $template->source || empty( $template->content ) ) {
			return $template;
		}

		$rewritten = $this->migration->rewrite_block_markup( $template->content );

		if ( null !== $rewritten ) {
			$template->content = $rewritten;
		}

		return $template;
	}
}
