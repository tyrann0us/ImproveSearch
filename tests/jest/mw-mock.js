/**
 * Minimal MediaWiki JS environment mock for testing the resource modules.
 *
 * Sets up window.mw (config, Api, hook) so the scripts can run in a JSDOM
 * environment without the full ResourceLoader runtime. Loading the module
 * under test is the test file's job: `require()` has to be called from there,
 * or `jest.resetModules()` will not give it a fresh instance.
 */

'use strict';

/**
 * Build a fresh mw mock and attach it to the given window.
 * Returns helpers that tests use to inspect and trigger behaviour.
 *
 * @param {Window} win
 * @param {Object} [initialConfig] mw.config contents
 */
function createMwEnv( win, initialConfig ) {
	// ── mw.config (stands in for mw.Map, which also takes a whole object) ──

	const store = Object.assign( {}, initialConfig || {} );
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

	// ── mw.hook ─────────────────────────────────────────────────

	const hooks = {};
	const hook = ( name ) => ( {
		add: ( handler ) => {
			hooks[ name ] = hooks[ name ] || [];
			hooks[ name ].push( handler );
		},
	} );

	win.mw = { config, Api: MwApi, hook };

	// ── Return helpers for tests ────────────────────────────────

	return {
		mw: win.mw,
		config,
		requests,

		/**
		 * Fire a hook the module under test subscribed to.
		 *
		 * @param {string}               name
		 * @param {...(string|Function)} args what the firing side passes on
		 */
		fireHook: ( name, ...args ) =>
			( hooks[ name ] || [] ).forEach( ( handler ) =>
				handler( ...args )
			),

		/**
		 * @param {Function} handler receives the request params, returns a promise
		 */
		answerApiWith: ( handler ) => {
			respond = handler;
		},
	};
}

module.exports = { createMwEnv };
