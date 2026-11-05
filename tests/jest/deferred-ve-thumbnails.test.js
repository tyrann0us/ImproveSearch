/**
 * Tests for resources/deferred-ve-thumbnails.js.
 *
 * Two things have to hold: the title lookup itself must stay free of the
 * page-image parameters, and the images must arrive afterwards on the icon
 * TitleOptionWidget renders anyway.
 *
 * The module caches titles for the lifetime of the page, which is right in a
 * browser and awkward in a test runner that reuses one module instance. So
 * every test works with titles of its own.
 */

'use strict';

const { createMwEnv } = require( './mw-mock' );

const RESOURCE = '../../resources/deferred-ve-thumbnails.js';

/**
 * A stand-in for mw.widgets.TitleWidget, carrying only what the module wraps.
 *
 * @param {boolean} showImages
 * @return {Object} the widget prototype and a widget bound to it
 */
function createTitleWidget( showImages ) {
	const proto = {
		getApiParams: () => ( {
			action: 'query',
			prop: [ 'info', 'pageprops', 'pageimages' ],
			generator: 'prefixsearch',
			pithumbsize: 80,
			pilimit: 10,
		} ),
		getOptionWidgetData: ( title ) => ( { data: title } ),
		getOptionsFromData( data ) {
			return data.titles.map( ( title ) =>
				createOption( this.getOptionWidgetData( title, {} ).data )
			);
		},
	};

	return {
		proto,
		widget: Object.assign( Object.create( proto ), { showImages } ),
	};
}

/**
 * A stand-in for a rendered menu entry. The module touches $icon only.
 *
 * @param {string} title
 * @return {Object} the fake menu entry
 */
function createOption( title ) {
	const painted = { classes: [], backgroundImage: null };

	return {
		getData: () => title,
		painted,
		$icon: {
			addClass( names ) {
				painted.classes.push( names );
				return this;
			},
			css( property, value ) {
				if ( property === 'background-image' ) {
					painted.backgroundImage = value;
				}
				return this;
			},
		},
	};
}

/**
 * Load the module and let it patch the prototype.
 *
 * @param {Object} env   as returned by createMwEnv()
 * @param {Object} proto the widget prototype to patch
 */
function activate( env, proto ) {
	global.mw = env.mw;
	global.mw.widgets = { TitleWidget: { prototype: proto } };
	jest.resetModules();
	require( RESOURCE );
	env.fireHook( 've.activationComplete' );
}

/**
 * Register a second class holding its own copies of the methods, the way
 * OO.mixinClass leaves TitleSearchWidget when mediawiki.widgets loads.
 *
 * @param {Object} env   as returned by createMwEnv()
 * @param {Object} proto the base prototype
 * @return {Object} the copy the link dialog would actually call
 */
function activateWithMixinCopy( env, proto ) {
	const copy = Object.assign( {}, proto );
	global.mw = env.mw;
	global.mw.widgets = {
		TitleWidget: { prototype: proto },
		TitleSearchWidget: { prototype: copy },
	};
	jest.resetModules();
	require( RESOURCE );
	env.fireHook( 've.activationComplete' );

	return copy;
}

/** Let the API promise settle. */
async function settle() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'deferred VisualEditor thumbnails', () => {
	it( 'takes the image parameters out of the title lookup', () => {
		const env = createMwEnv( global, {} );
		const { proto, widget } = createTitleWidget( true );
		activate( env, proto );

		const params = widget.getApiParams( 'weber' );

		expect( params.prop ).toEqual( [ 'info', 'pageprops' ] );
		expect( params.pithumbsize ).toBeUndefined();
		expect( params.pilimit ).toBeUndefined();
		expect( params.generator ).toBe( 'prefixsearch' );
	} );

	it( 'leaves a widget alone that never wanted images', () => {
		const env = createMwEnv( global, {} );
		const { proto, widget } = createTitleWidget( false );
		activate( env, proto );

		expect( widget.getApiParams( 'weber' ).prop ).toContain( 'pageimages' );
	} );

	it( 'fetches the images afterwards and paints them', async () => {
		const env = createMwEnv( global, {} );
		const { proto, widget } = createTitleWidget( true );
		activate( env, proto );

		env.answerApiWith( () =>
			Promise.resolve( {
				query: {
					pages: [
						{
							title: 'Weavers Lane',
							thumbnail: { source: 'https://wiki/weavers.png' },
						},
						{ title: 'Mill Road' },
					],
				},
			} )
		);

		const options = widget.getOptionsFromData( {
			titles: [ 'Weavers Lane', 'Mill Road' ],
		} );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect( env.requests[ 0 ].titles ).toEqual( [
			'Weavers Lane',
			'Mill Road',
		] );
		expect( env.requests[ 0 ].prop ).toBe( 'pageimages' );

		expect( options[ 0 ].painted.backgroundImage ).toBe(
			'url("https://wiki/weavers.png")'
		);
		expect( options[ 0 ].painted.classes.join( ' ' ) ).toContain(
			'mw-widget-titleOptionWidget-hasImage'
		);
		// A page without an image keeps the article icon.
		expect( options[ 1 ].painted.backgroundImage ).toBeNull();
	} );

	it( 'asks about a title only once', async () => {
		const env = createMwEnv( global, {} );
		const { proto, widget } = createTitleWidget( true );
		activate( env, proto );

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

		widget.getOptionsFromData( { titles: [ 'Anvil Yard' ] } );
		await settle();
		const second = widget.getOptionsFromData( {
			titles: [ 'Anvil Yard' ],
		} );
		await settle();

		expect( env.requests ).toHaveLength( 1 );
		expect( second[ 0 ].painted.backgroundImage ).toBe(
			'url("https://wiki/anvil.png")'
		);
	} );

	it( 'patches the copies OO.mixinClass leaves behind', () => {
		const env = createMwEnv( global, {} );
		const { proto } = createTitleWidget( true );
		const copy = activateWithMixinCopy( env, proto );
		const widget = Object.assign( Object.create( copy ), {
			showImages: true,
		} );

		expect( widget.getApiParams( 'weber' ).prop ).toEqual( [
			'info',
			'pageprops',
		] );
	} );

	it( 'survives an API error without breaking the menu', async () => {
		const env = createMwEnv( global, {} );
		const { proto, widget } = createTitleWidget( true );
		activate( env, proto );

		env.answerApiWith( () => Promise.reject( new Error( 'no network' ) ) );

		const options = widget.getOptionsFromData( {
			titles: [ 'Kiln Street' ],
		} );
		await settle();

		expect( options[ 0 ].painted.backgroundImage ).toBeNull();
	} );
} );
