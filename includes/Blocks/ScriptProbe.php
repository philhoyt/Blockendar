<?php
/**
 * Marks the document as JavaScript-capable before a filter block is painted.
 *
 * @package Blockendar
 */

declare( strict_types=1 );

namespace Blockendar\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints a one-line inline script that adds a class to <html>.
 *
 * The filter blocks render a trigger and a panel, and their view scripts turn
 * the pair into a popover. Those scripts are deferred, so the browser can paint
 * the page with the panel open — the full venue list, the date fields — and
 * collapse it a moment later when the script adds `is-enhanced`. The stylesheet
 * cannot hide the panel up front, because a visitor whose JavaScript never runs
 * must keep it.
 *
 * This resolves that: the class lands on <html> synchronously, just ahead of the
 * first filter block's markup, so the stylesheet can render the closed state
 * before the view script arrives. No JavaScript means no class, and the panel
 * stays visible as before.
 */
class ScriptProbe {

	public const HTML_CLASS = 'blockendar-js';

	/**
	 * Whether the probe has been printed during this request.
	 *
	 * @var bool
	 */
	private static bool $printed = false;

	/**
	 * Print the probe once per request, on the front end only.
	 *
	 * Called from a filter block's render callback before its own markup. The
	 * editor never sees it: those blocks preview with JSX rather than
	 * render.php, and the class has no meaning outside a front-end document.
	 */
	public static function print_once(): void {
		if ( self::$printed || is_admin() || wp_is_json_request() ) {
			return;
		}

		self::$printed = true;

		wp_print_inline_script_tag(
			'document.documentElement.classList.add("' . self::HTML_CLASS . '");'
		);
	}

	/**
	 * Forget that the probe was printed. For tests, which render many pages in
	 * one request.
	 */
	public static function reset(): void {
		self::$printed = false;
	}
}
