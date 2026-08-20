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

	/*
	 * Turn the welcome guide off rather than racing its markup. Waiting for the
	 * overlay and closing it does not work: the guide mounts after the editor is
	 * ready, so a check on load finds nothing and the overlay then appears and
	 * swallows pointer events across the whole page — including the sidebar.
	 *
	 * The preference scope differs between WordPress versions, so set both.
	 */
	await page
		.waitForFunction( () => window.wp?.data?.dispatch, { timeout: 30000 } )
		.catch( () => {} );

	await page
		.evaluate( () => {
			const preferences = window.wp.data.dispatch( 'core/preferences' );

			preferences?.set( 'core/edit-post', 'welcomeGuide', false );
			preferences?.set( 'core', 'welcomeGuide', false );
		} )
		.catch( () => {} );

	await page
		.locator( '.components-modal__screen-overlay' )
		.first()
		.waitFor( { state: 'detached', timeout: 15000 } )
		.catch( () => {} );

	const iframe = page.locator( 'iframe[name="editor-canvas"]' );

	if ( await iframe.count() ) {
		await iframe.waitFor( { timeout: 30000 } );
		return page.frameLocator( 'iframe[name="editor-canvas"]' );
	}

	return page;
}

module.exports = { loginAsAdmin, openEditor };
