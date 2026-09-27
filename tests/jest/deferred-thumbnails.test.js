/**
 * Tests for resources/deferred-thumbnails.js.
 *
 * Two things have to hold: the search request itself must stay free of the
 * page-image parameters even though MobileFrontend sets them again from
 * inside mobile.startup, and the images must arrive afterwards in the shape
 * PageList.js would have rendered them.
 *
 * The module caches page ids for the lifetime of the page, which is right in a
 * browser and awkward in a test runner that reuses one document. So every test
 * works with page ids of its own.
 */

'use strict';

const { createMwEnv } = require( './mw-mock' );

const RESOURCE = '../../resources/deferred-thumbnails.js';

/**
 * Run the module under test against a fresh mw mock.
 *
 * The require has to happen here rather than inside mw-mock.js: a helper
 * module loaded before the first jest.resetModules() keeps handing back the
 * cached instance.
 *
 * @param {Object} env as returned by createMwEnv()
 */
function loadDeferredThumbnails( env ) {
	global.mw = env.mw;
	jest.resetModules();
	require( RESOURCE );
}

/** Let the MutationObserver microtask and the setTimeout( …, 0 ) run. */
async function settle() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

function isClean( config ) {
	const params = config.get( 'wgMFSearchAPIParams' );
	return (
		config.get( 'wgMFQueryPropModules' ).indexOf( 'pageimages' ) === -1 &&
		params.piprop === undefined &&
		params.pithumbsize === undefined &&
		params.pilimit === undefined &&
		params.ppprop === 'displaytitle'
	);
}

function thumbnail( source, width, height ) {
	return { source, width, height };
}

function pages( entries ) {
	return Promise.resolve( { query: { pages: entries } } );
}

function renderResultList( ids ) {
	document.body.innerHTML =
		'<ul class="mw-mf-page-list">' +
		ids
			.map(
				( id ) =>
					'<li class="page-summary" data-id="' +
					id +
					'"><div class="list-thumb">' +
					'<span class="mf-icon-image"></span></div></li>'
			)
			.join( '' ) +
		'</ul>';
}

function thumbElementFor( id ) {
	return document.querySelector(
		'li.page-summary[data-id="' + id + '"] .list-thumb'
	);
}

describe( 'deferred-thumbnails.js', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	test( 'the image parameters are gone right after loading', () => {
		const env = createMwEnv( window );

		loadDeferredThumbnails( env );

		expect( isClean( env.config ) ).toBe( true );
	} );

	test( 'they are gone again once mobile.startup has run', async () => {
		const env = createMwEnv( window );
		loadDeferredThumbnails( env );

		// MobileFrontend really does overwrite them.
		env.replayMobileFrontendConfig();
		expect( isClean( env.config ) ).toBe( false );

		env.letMobileStartupFinish();
		await settle();

		expect( isClean( env.config ) ).toBe( true );
	} );

	test( 'survives a missing mobile.startup module', async () => {
		const env = createMwEnv( window );
		loadDeferredThumbnails( env );

		env.failMobileStartup();
		await settle();

		// The first strip still stands; nothing threw.
		expect( isClean( env.config ) ).toBe( true );
	} );

	test( 'works when MobileFrontend has set nothing yet', async () => {
		const env = createMwEnv( window, {} );
		loadDeferredThumbnails( env );

		// Nothing to take out of the prop modules, so they stay untouched.
		expect( env.config.get( 'wgMFQueryPropModules' ) ).toBeNull();
		expect( env.config.get( 'wgMFSearchAPIParams' ) ).toEqual( {} );

		renderResultList( [ 30 ] );
		await settle();

		// Falls back to MobileFrontend's own "tiny" size.
		expect( env.requests[ 0 ].pithumbsize ).toBe( 120 );
	} );

	test( 'fetches the images for the entries on screen and paints them', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () =>
			pages( [
				{
					pageid: 10,
					thumbnail: thumbnail( 'http://example.org/a.png', 120, 80 ),
				},
			] )
		);
		loadDeferredThumbnails( env );

		renderResultList( [ 10 ] );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect( env.requests[ 0 ] ).toMatchObject( {
			action: 'query',
			pageids: '10',
			prop: 'pageimages',
			pithumbsize: 120,
		} );

		const thumbEl = thumbElementFor( 10 );
		expect( thumbEl.style.backgroundImage ).toContain( 'a.png' );
		// Landscape, so the y variant.
		expect( thumbEl.classList.contains( 'list-thumb-y' ) ).toBe( true );
		expect( thumbEl.querySelector( '.mf-icon-image' ) ).toBeNull();
	} );

	test( 'skips entries that already carry an image or have no thumb box', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () =>
			pages( [
				{ pageid: 22, thumbnail: {} },
				{
					pageid: 23,
					thumbnail: thumbnail( 'http://example.org/b.png', 80, 120 ),
				},
			] )
		);
		loadDeferredThumbnails( env );

		document.body.innerHTML =
			'<ul class="mw-mf-page-list">' +
			// PageList.js is about to apply this one itself.
			'<li class="page-summary" data-id="20"><div class="list-thumb" data-style="x"></div></li>' +
			// No .list-thumb at all.
			'<li class="page-summary" data-id="21"></li>' +
			// A thumbnail entry whose image turns out to have no source.
			'<li class="page-summary" data-id="22"><div class="list-thumb"></div></li>' +
			'<li class="page-summary" data-id="23"><div class="list-thumb"></div></li>' +
			'</ul>';
		await settle();

		expect( env.requests[ 0 ].pageids ).toBe( '22|23' );
		expect( thumbElementFor( 22 ).style.backgroundImage ).toBe( '' );
		// Portrait, so the x variant.
		expect(
			thumbElementFor( 23 ).classList.contains( 'list-thumb-x' )
		).toBe( true );
	} );

	test( 'one request covers a page listed twice, and spare answers are dropped', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () =>
			pages( [
				{
					pageid: 40,
					thumbnail: thumbnail( 'http://example.org/c.png', 120, 80 ),
				},
				// A page the request never asked about.
				{
					pageid: 41,
					thumbnail: thumbnail( 'http://example.org/d.png', 1, 1 ),
				},
			] )
		);
		loadDeferredThumbnails( env );

		renderResultList( [ 40, 40 ] );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect( env.requests[ 0 ].pageids ).toBe( '40' );
		document
			.querySelectorAll( 'li.page-summary[data-id="40"] .list-thumb' )
			.forEach( ( element ) => {
				expect( element.style.backgroundImage ).toContain( 'c.png' );
			} );
	} );

	test( 'an answer without pages changes nothing', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () => Promise.resolve( {} ) );
		loadDeferredThumbnails( env );

		renderResultList( [ 50 ] );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect(
			thumbElementFor( 50 ).querySelector( '.mf-icon-image' )
		).not.toBeNull();
	} );

	test( 'two mutations in the same tick share one pass', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () => pages( [ { pageid: 80 } ] ) );
		loadDeferredThumbnails( env );

		renderResultList( [ 80 ] );
		// Let the observer run, but not the timeout it scheduled.
		await Promise.resolve();
		document.body.appendChild( document.createElement( 'div' ) );
		await Promise.resolve();

		await settle();

		expect( env.requests ).toHaveLength( 1 );
	} );

	test( 'a page id already looked up is not asked for twice', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () => pages( [ { pageid: 60 } ] ) );
		loadDeferredThumbnails( env );

		renderResultList( [ 60 ] );
		await settle();
		// The next keystroke renders the same page again.
		renderResultList( [ 60 ] );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
	} );

	test( 'a failing request leaves the placeholder alone', async () => {
		const env = createMwEnv( window );
		env.answerApiWith( () => Promise.reject( new Error( 'http' ) ) );
		loadDeferredThumbnails( env );

		renderResultList( [ 70 ] );
		await settle();

		expect(
			thumbElementFor( 70 ).querySelector( '.mf-icon-image' )
		).not.toBeNull();
	} );
} );
