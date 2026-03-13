/* eslint-env jest */
/**
 * Tests for the escapeHtml utility used in event-map/view.js.
 *
 * The function is defined inline inside the view IIFE; we mirror its
 * implementation here so the tests are self-contained and any change
 * to view.js that breaks the spec will require updating both.
 */

// Exact implementation from src/blocks/event-map/view.js
const escapeHtml = ( str ) =>
	str.replace(
		/[&<>"']/g,
		( c ) =>
			( {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#39;',
			} )[ c ]
	);

describe( 'escapeHtml', () => {
	test( 'escapes < and > in a script tag', () => {
		expect( escapeHtml( '<script>' ) ).toBe( '&lt;script&gt;' );
	} );

	test( 'escapes double-quotes', () => {
		expect( escapeHtml( '"value"' ) ).toBe( '&quot;value&quot;' );
	} );

	test( 'escapes single-quotes', () => {
		expect( escapeHtml( "it's" ) ).toBe( 'it&#39;s' );
	} );

	test( 'escapes ampersands', () => {
		expect( escapeHtml( 'A & B' ) ).toBe( 'A &amp; B' );
	} );

	test( 'leaves a clean string unchanged', () => {
		expect( escapeHtml( 'Hello World' ) ).toBe( 'Hello World' );
	} );

	test( 'escapes all dangerous characters in a combined string', () => {
		expect( escapeHtml( '<b class="x">A & B</b>' ) ).toBe(
			'&lt;b class=&quot;x&quot;&gt;A &amp; B&lt;/b&gt;'
		);
	} );
} );
