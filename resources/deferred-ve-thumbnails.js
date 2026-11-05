/*!
 * Fetch the page images of VisualEditor's title widgets separately.
 *
 * mw.widgets.TitleWidget asks for prop=pageimages in the same request as the
 * titles, so MediaWiki fetches the file data from the foreign repository
 * before it answers at all. Every keystroke in the link dialog waits for it.
 * Measured against a cold cache, one lookup took between 1.4 and 3.0 seconds
 * instead of half of one.
 *
 * So: drop the image parameters from that request, let the menu appear with
 * the article icons the widget renders anyway, and fill the images in
 * afterwards from a second request.
 */
( function () {
	'use strict';

	// The size TitleWidget asks for in getApiParams().
	const thumbSize = 80;
	// Title to image URL, or null once we know the page has no image.
	// Every keystroke builds a new menu, mostly of the same pages.
	const known = new Map();

	let api = null;
	let patched = false;

	/**
	 * Put one image into a menu entry, in the shape TitleOptionWidget would
	 * have rendered it.
	 *
	 * @param {Object}      option Menu entry rendered by TitleOptionWidget
	 * @param {string|null} url    Thumbnail URL
	 */
	function paint( option, url ) {
		if ( ! url ) {
			return;
		}

		option.$icon
			.addClass( 'mw-widget-titleOptionWidget-hasImage mw-no-invert' )
			// Quoted: a thumbnail URL may carry spaces or brackets.
			.css( 'background-image', 'url("' + url + '")' );
	}

	/**
	 * Fetch the images for the entries of one menu and paint them.
	 *
	 * @param {Object[]} options Menu entries rendered by TitleOptionWidget
	 * @param {Map}      titles  Option data value to canonical title
	 */
	function fill( options, titles ) {
		const waiting = new Map();

		options.forEach( ( option ) => {
			const data = option.getData();
			const title = titles.get( data ) || data;

			if ( known.has( title ) ) {
				paint( option, known.get( title ) );
				return;
			}

			if ( ! waiting.has( title ) ) {
				waiting.set( title, [] );
			}
			waiting.get( title ).push( option );
		} );

		if ( ! waiting.size ) {
			return;
		}

		const wanted = Array.from( waiting.keys() );
		api = api || new mw.Api();

		api.get( {
			action: 'query',
			formatversion: 2,
			titles: wanted,
			prop: 'pageimages',
			piprop: 'thumbnail',
			pithumbsize: thumbSize,
			pilimit: wanted.length,
		} )
			.then( ( data ) => {
				const pages = ( data.query && data.query.pages ) || [];

				pages.forEach( ( page ) => {
					const url =
						( page.thumbnail && page.thumbnail.source ) || null;

					// Remember the misses too, so typing on does not ask again.
					known.set( page.title, url );
					( waiting.get( page.title ) || [] ).forEach( ( option ) =>
						paint( option, url )
					);
				} );
			} )
			.catch( () => {
				// An image that does not arrive is not worth a broken dialog.
				// The article icon stays, and the next menu tries again.
			} );
	}

	/**
	 * Wrap the three methods that carry the images through one widget class.
	 *
	 * @param {Object} proto Prototype of a widget built from mw.widgets.TitleWidget
	 */
	function wrap( proto ) {
		const getApiParams = proto.getApiParams;
		const getOptionWidgetData = proto.getOptionWidgetData;
		const getOptionsFromData = proto.getOptionsFromData;

		proto.getApiParams = function ( query ) {
			const params = getApiParams.call( this, query );
			if ( ! this.showImages ) {
				return params;
			}

			params.prop = params.prop.filter(
				( prop ) => prop !== 'pageimages'
			);
			delete params.pithumbsize;
			delete params.pilimit;

			return params;
		};

		// A widget with a namespace set may label its options relatively,
		// and the API needs the full title. Kept per widget, because two of
		// them can be on a page at once.
		proto.getOptionWidgetData = function ( title, data ) {
			const widgetData = getOptionWidgetData.call( this, title, data );
			this.improveSearchTitles.set( widgetData.data, title );

			return widgetData;
		};

		proto.getOptionsFromData = function ( data ) {
			this.improveSearchTitles = new Map();
			const options = getOptionsFromData.call( this, data );
			if ( this.showImages ) {
				fill( options, this.improveSearchTitles );
			}

			return options;
		};
	}

	/**
	 * Wrap every widget class that carries these methods.
	 *
	 * Patching mw.widgets.TitleWidget alone would not do: OO.mixinClass
	 * copies its methods onto TitleSearchWidget and TitleInputWidget when
	 * mediawiki.widgets loads, and those copies are what the link dialog
	 * actually calls.
	 */
	function patch() {
		if ( patched || ! mw.widgets ) {
			return;
		}

		const protos = Object.keys( mw.widgets )
			.map( ( name ) => mw.widgets[ name ] )
			.filter(
				( candidate ) =>
					candidate &&
					candidate.prototype &&
					typeof candidate.prototype.getApiParams === 'function' &&
					typeof candidate.prototype.getOptionsFromData === 'function'
			)
			.map( ( candidate ) => candidate.prototype );

		if ( ! protos.length ) {
			return;
		}

		patched = true;
		protos.forEach( wrap );
	}

	// The link dialog cannot open before VisualEditor is up, and by then its
	// modules, mediawiki.widgets among them, have been loaded. Patching here
	// rather than on page load keeps mediawiki.widgets from being pulled in
	// for readers who never open the editor.
	mw.hook( 've.activationComplete' ).add( patch );
} )();
