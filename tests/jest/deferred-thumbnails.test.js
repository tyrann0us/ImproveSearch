/**
 * Tests for resources/deferred-thumbnails.js.
 *
 * Two things have to hold: the search request must carry the parameter that
 * tells the server to leave the images out, and the images must arrive
 * afterwards on the placeholder the menu renders anyway.
 *
 * The module caches titles for the lifetime of the page, which is right in a
 * browser and awkward in a test runner that reuses one module instance. So
 * every test works with titles of its own.
 */

'use strict';

const { createMwEnv } = require( './mw-mock' );

const RESOURCE = '../../resources/deferred-thumbnails.js';

/**
 * The part of Minerva's search form the module watches, with an empty menu.
 *
 * @return {Element} the menu the tests render entries into
 */
function createSearchForm() {
	// A body of its own per test: the module watches the document, and the
	// instance the test before it loaded is still watching the old one.
	document.documentElement.replaceChild(
		document.createElement( 'body' ),
		document.body
	);
	document.body.innerHTML =
		'<form class="minerva-search-form">' +
		'<div class="search-box"><div class="cdx-typeahead-search">' +
		'<ul class="cdx-typeahead-search__menu"></ul>' +
		'</div></div></form>';

	return document.querySelector( '.cdx-typeahead-search__menu' );
}

/**
 * Render a menu the way Codex renders one without thumbnails.
 *
 * @param {Element}  menu
 * @param {string[]} titles
 */
function render( menu, titles ) {
	menu.innerHTML = titles
		.map(
			( title ) =>
				'<li class="cdx-menu-item">' +
				'<span class="cdx-thumbnail cdx-menu-item__thumbnail">' +
				'<span class="cdx-thumbnail__placeholder"></span>' +
				'</span>' +
				'<span class="cdx-menu-item__text">' +
				'<span class="cdx-menu-item__text__label">' +
				title +
				'</span></span></li>'
		)
		.join( '' );
}

/**
 * @param {Element} menu
 * @param {number}  index
 * @return {string|null} the background image of that entry, null while bare
 */
function paintedImage( menu, index ) {
	const image = menu
		.querySelectorAll( '.cdx-menu-item' )
		[ index ].querySelector( '.cdx-thumbnail__image' );

	return image ? image.style.backgroundImage : null;
}

/** Let the MutationObserver and the API promise settle. */
async function settle() {
	for ( let i = 0; i < 4; i++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
}

/**
 * Load the module into a fresh environment.
 *
 * @param {boolean} [searched] Whether a first search has happened, which is
 *                             when the module starts watching the menu
 * @return {Object} as returned by createMwEnv()
 */
function activate( searched = true ) {
	const env = createMwEnv( global, {} );
	global.mw = env.mw;
	jest.resetModules();
	require( RESOURCE );
	if ( searched ) {
		env.fireHook( 'typeaheadSearch.appendUrlParams', () => {} );
	}

	return env;
}

describe( 'deferred typeahead thumbnails', () => {
	it( 'asks the server to answer without thumbnails', () => {
		createSearchForm();
		const env = activate();
		const appended = {};

		env.fireHook( 'typeaheadSearch.appendUrlParams', ( key, value ) => {
			appended[ key ] = value;
		} );

		expect( appended ).toEqual( { improvesearchthumbs: '0' } );
	} );

	it( 'stays out of the way until a search happens', async () => {
		const menu = createSearchForm();
		const env = activate( false );

		render( menu, [ 'Tanner Row' ] );
		await settle();

		expect( env.requests ).toHaveLength( 0 );
	} );

	it( 'fetches the images afterwards and paints them', async () => {
		const menu = createSearchForm();
		const env = activate();

		env.answerApiWith( () =>
			Promise.resolve( {
				query: {
					pages: [
						{
							title: 'Stone Bridge',
							thumbnail: { source: 'https://wiki/stone.png' },
						},
						{ title: 'Mill Road' },
					],
				},
			} )
		);

		render( menu, [ 'Stone Bridge', 'Mill Road' ] );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect( env.requests[ 0 ].titles ).toEqual( [
			'Stone Bridge',
			'Mill Road',
		] );
		expect( env.requests[ 0 ].prop ).toBe( 'pageimages' );

		expect( paintedImage( menu, 0 ) ).toBe(
			'url("https://wiki/stone.png")'
		);
		// A page without an image keeps the placeholder icon.
		expect( paintedImage( menu, 1 ) ).toBeNull();
	} );

	it( 'paints the next menu from the cache, without asking again', async () => {
		const menu = createSearchForm();
		const env = activate();

		env.answerApiWith( () =>
			Promise.resolve( {
				query: {
					pages: [
						{
							title: 'Anvil Yard',
							thumbnail: { source: 'https://wiki/anvil.png' },
						},
					],
				},
			} )
		);

		render( menu, [ 'Anvil Yard' ] );
		await settle();
		// What Vue does on the next keystroke: the entries are built anew,
		// and the image painted into the old ones is gone with them.
		render( menu, [ 'Anvil Yard' ] );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect( paintedImage( menu, 0 ) ).toBe(
			'url("https://wiki/anvil.png")'
		);
	} );

	it( 'leaves the footer entry alone', async () => {
		const menu = createSearchForm();
		const env = activate();

		menu.innerHTML =
			'<li class="cdx-menu-item">' +
			'<span class="cdx-typeahead-search__search-footer__text">' +
			'Search for pages containing kiln</span></li>';
		await settle();

		expect( env.requests ).toHaveLength( 0 );
	} );

	it( 'survives an API error without breaking the menu', async () => {
		const menu = createSearchForm();
		const env = activate();

		env.answerApiWith( () => Promise.reject( new Error( 'no network' ) ) );

		render( menu, [ 'Kiln Street' ] );
		await settle();

		expect( paintedImage( menu, 0 ) ).toBeNull();
	} );
} );
