/**
 * Minimal MediaWiki JS environment mock for testing the resource modules.
 *
 * Sets up window.mw (config, Api, loader) so the scripts can run in a JSDOM
 * environment without the full ResourceLoader runtime. Loading the module
 * under test is the test file's job: `require()` has to be called from there,
 * or `jest.resetModules()` will not give it a fresh instance.
 */

'use strict';

/**
 * What MobileFrontend puts into mw.config, both at page load and again when
 * its own `mobile.startup` module executes.
 *
 * @return {Object} a fresh copy, so tests can mutate it freely
 */
function mobileFrontendConfig() {
	return {
		wgMFThumbnailSizes: { tiny: 120, small: 220 },
		wgMFQueryPropModules: [ 'pageprops', 'pageimages' ],
		wgMFSearchAPIParams: {
			ppprop: 'displaytitle',
			piprop: 'thumbnail',
			pithumbsize: 220,
			pilimit: 50,
		},
	};
}

/**
 * Build a fresh mw mock and attach it to the given window.
 * Returns helpers that tests use to inspect and trigger behaviour.
 *
 * @param {Window} win
 * @param {Object} [initialConfig] mw.config contents, defaults to MobileFrontend's
 */
function createMwEnv( win, initialConfig ) {
	// ── mw.config (stands in for mw.Map, which also takes a whole object) ──

	const store = Object.assign( {}, initialConfig || mobileFrontendConfig() );
	const config = {
		get( key ) {
			return store[ key ] !== undefined ? store[ key ] : null;
		},
		set( key, value ) {
			if ( typeof key === 'object' ) {
				Object.assign( store, key );
			} else {
				store[ key ] = value;
			}
		},
	};

	// ── mw.Api ──────────────────────────────────────────────────

	const requests = [];
	let respond = () => Promise.resolve( { query: { pages: [] } } );

	function MwApi() {
		this.get = ( params ) => {
			requests.push( params );
			return respond( params );
		};
	}

	// ── mw.loader ───────────────────────────────────────────────

	let resolveUsing;
	let rejectUsing;
	const loader = {
		using: () =>
			new Promise( ( resolve, reject ) => {
				resolveUsing = resolve;
				rejectUsing = reject;
			} ),
	};

	win.mw = { config, Api: MwApi, loader };

	// ── Return helpers for tests ────────────────────────────────

	return {
		mw: win.mw,
		config,
		requests,

		/** Let the pending mw.loader.using( 'mobile.startup' ) settle. */
		letMobileStartupFinish: () => resolveUsing && resolveUsing(),

		/** MobileFrontend is not installed, so the module never arrives. */
		failMobileStartup: () => rejectUsing && rejectUsing( new Error( 'no module' ) ),

		/** Replay what MobileFrontend does when mobile.startup executes. */
		replayMobileFrontendConfig: () => config.set( mobileFrontendConfig() ),

		/**
		 * @param {Function} handler receives the request params, returns a promise
		 */
		answerApiWith: ( handler ) => {
			respond = handler;
		},
	};
}

module.exports = { createMwEnv };
