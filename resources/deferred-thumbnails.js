/*!
 * Fetch the page images of the mobile search overlay separately.
 *
 * MobileFrontend asks for the thumbnails in the same request as the results
 * (SearchGateway.js via extendSearchParams.js). For every hit whose file is
 * not cached yet, MediaWiki then fetches the file data from the foreign
 * repository before it can answer at all, so the whole list waits for the
 * slowest image. Measured against a cold cache, a fifteen-hit search took
 * between two and eight seconds instead of a third of one.
 *
 * So: drop the image parameters from that request, let the list appear with
 * the placeholder icons MobileFrontend renders anyway, and fill the images in
 * afterwards from a second request.
 */
( function () {
	'use strict';

	// MobileFrontend asks for this width in SearchGateway.js.
	const thumbSize = ( mw.config.get( 'wgMFThumbnailSizes' ) || {} ).tiny || 120;
	// Page id to thumbnail, or null once we know the page has no image.
	// Every keystroke renders a new list, mostly of the same pages.
	const known = new Map();

	let api = null;
	let scheduled = false;

	/**
	 * Take the page images out of the search request itself.
	 *
	 * Both variables are read per search, so changing them here is enough.
	 * MobileFrontend adds them unconditionally as soon as PageImages is
	 * installed and offers no setting to leave them out.
	 */
	function stripImageParams() {
		const props = mw.config.get( 'wgMFQueryPropModules' ) || [];
		if ( props.indexOf( 'pageimages' ) !== -1 ) {
			mw.config.set( 'wgMFQueryPropModules', props.filter( ( prop ) => prop !== 'pageimages' ) );
		}

		const params = Object.assign( {}, mw.config.get( 'wgMFSearchAPIParams' ) );
		delete params.piprop;
		delete params.pithumbsize;
		delete params.pilimit;
		mw.config.set( 'wgMFSearchAPIParams', params );
	}

	/**
	 * Put one image into a list entry, in the shape PageList.js would have
	 * rendered it: background image on .list-thumb, orientation class, and no
	 * placeholder icon left behind.
	 *
	 * @param {Element} thumbEl The .list-thumb element
	 * @param {Object|null} thumb Thumbnail as returned by prop=pageimages
	 */
	function paint( thumbEl, thumb ) {
		if ( !thumb || !thumb.source ) {
			return;
		}

		const isLandscape = thumb.width > thumb.height;
		thumbEl.classList.toggle( 'list-thumb-y', isLandscape );
		thumbEl.classList.toggle( 'list-thumb-x', !isLandscape );
		// Quoted: a thumbnail URL may carry spaces or brackets.
		thumbEl.style.backgroundImage = 'url("' + thumb.source + '")';

		const placeholder = thumbEl.querySelector( '.mf-icon-image' );
		if ( placeholder ) {
			placeholder.remove();
		}
	}

	/**
	 * Every list entry still waiting for an image.
	 *
	 * @return {Map} page id to the .list-thumb elements carrying that id
	 */
	function openEntries() {
		const entries = new Map();

		document.querySelectorAll( '.mw-mf-page-list li.page-summary[data-id]' ).forEach( ( item ) => {
			const id = Number( item.getAttribute( 'data-id' ) );
			const thumbEl = item.querySelector( '.list-thumb' );
			// data-style means the entry brought its own image and
			// PageList.js is about to apply it.
			if ( !id || !thumbEl || thumbEl.style.backgroundImage || thumbEl.dataset.style ) {
				return;
			}

			if ( known.has( id ) ) {
				paint( thumbEl, known.get( id ) );
				return;
			}

			if ( !entries.has( id ) ) {
				entries.set( id, [] );
			}
			entries.get( id ).push( thumbEl );
		} );

		return entries;
	}

	/**
	 * Fetch the images for the entries on screen and paint them.
	 */
	function fill() {
		scheduled = false;

		const entries = openEntries();
		if ( !entries.size ) {
			return;
		}

		// The overlay shows fifteen hits, so one request always covers them.
		const ids = Array.from( entries.keys() ).slice( 0, 50 );
		api = api || new mw.Api();

		api.get( {
			action: 'query',
			formatversion: 2,
			pageids: ids.join( '|' ),
			prop: 'pageimages',
			piprop: 'thumbnail',
			pithumbsize: thumbSize
		} ).then( ( data ) => {
			const pages = ( data.query && data.query.pages ) || [];

			pages.forEach( ( page ) => {
				const thumb = page.thumbnail || null;
				// Remember the misses too, so typing on does not ask again.
				known.set( page.pageid, thumb );
				( entries.get( page.pageid ) || [] ).forEach( ( thumbEl ) => paint( thumbEl, thumb ) );
			} );
		} ).catch( () => {
			// An image that does not arrive is not worth a broken search. The
			// placeholder icon stays, and the next list tries again.
		} );
	}

	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		setTimeout( fill, 0 );
	}

	// MobileFrontend does not put these variables into the startup module. It
	// ships them inside mobile.startup and sets them when that module runs,
	// which is after this one. Stripping them only now would be undone, so do
	// it again once MobileFrontend has had its say.
	stripImageParams();
	mw.loader.using( 'mobile.startup' ).then( stripImageParams, () => {} );

	// The overlay renders a fresh list on every keystroke.
	new MutationObserver( schedule ).observe( document.body, {
		childList: true,
		subtree: true
	} );
}() );
