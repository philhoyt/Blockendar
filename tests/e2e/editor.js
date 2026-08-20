/**
 * Helpers for driving the block editor in end-to-end tests.
 *
 * wp-env's default administrator credentials.
 */

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'password';

/**
 * Log in as administrator.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

/**
 * Open a post in the block editor and return a locator scope for its canvas.
 *
 * The canvas is iframed in current WordPress, but not in every configuration, so
 * this falls back to the page itself rather than assuming.
 *
 * @param {import('@playwright/test').Page} page   Playwright page.
 * @param {string|number}                   postId Post to edit.
 * @return {Promise<Object>} Locator scope for content inside the editor canvas.
 */
async function openEditor( page, postId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit`, {
		waitUntil: 'domcontentloaded',
	} );

	// The welcome guide covers the canvas on a fresh profile.
	const guide = page.locator(
		'.components-guide__container button[aria-label="Close"]'
	);
	await guide.click( { timeout: 8000 } ).catch( () => {} );

	const iframe = page.locator( 'iframe[name="editor-canvas"]' );

	if ( await iframe.count() ) {
		await iframe.waitFor( { timeout: 30000 } );
		return page.frameLocator( 'iframe[name="editor-canvas"]' );
	}

	return page;
}

module.exports = { loginAsAdmin, openEditor };
