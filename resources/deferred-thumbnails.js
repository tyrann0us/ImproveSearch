/*!
 * Fetch the page images of the typeahead search separately.
 *
 * The REST endpoint behind the Codex typeahead looks up a thumbnail for every
 * hit before it answers, and MediaWiki fetches the file data from the foreign
 * repository first, so the whole list waits for the slowest image. On a cold
 * cache that wait is several times the search itself.
 *
 * So: ask the endpoint to leave the images out, let the menu appear with the
 * placeholder icons it renders anyway, and fill them in from a second request.
 */
( function () {
	'use strict';

	// The size SearchResultThumbnailProvider uses for the endpoint.
	const thumbSize = 60;
	// Title to image URL, null while a request is out and once we know the
	// page has none. Every keystroke renders a new menu, mostly of the same
	// pages.
	const known = new Map();

	let api = null;
	let watching = false;

	// The PHP side of this extension reads the parameter and ends the
	// thumbnail hook before PageImages fills it in. The first search is also
	// the proof that this page has a typeahead at all, whatever the skin, so
	// the menu is watched from here rather than from page load.
	mw.hook( 'typeaheadSearch.appendUrlParams' ).add( ( append ) => {
		append( 'improvesearchthumbs', '0' );

		if ( watching ) {
			return;
		}
		watching = true;

		// Vue owns the menu and rebuilds it on every keystroke, so an image
		// painted into it is gone after the next render, and painting again
		// from the cache is the cheapest way to keep it. There is nothing
		// narrower to watch than the document: on a narrow screen Minerva
		// opens the search in a dialog of its own, outside the header.
		new MutationObserver( scan ).observe( document.body, {
			childList: true,
			subtree: true,
		} );
	} );

	/**
	 * Put one image into a menu entry, in the shape CdxThumbnail renders it.
	 *
	 * @param {Element}     item One rendered menu entry
	 * @param {string|null} url  Thumbnail URL
	 */
	function paint( item, url ) {
		const thumbnail = item.querySelector( '.cdx-thumbnail' );
		if (
			! url ||
			! thumbnail ||
			thumbnail.querySelector( '.cdx-thumbnail__image' )
		) {
			return;
		}

		const image = document.createElement( 'span' );
		image.className = 'cdx-thumbnail__image mw-no-invert';
		// Escaped the way CdxThumbnail escapes it: a URL may carry quotes.
		image.style.backgroundImage =
			'url("' + url.replace( /(["\\\n])/g, '\\$1' ) + '")';

		const placeholder = thumbnail.querySelector(
			'.cdx-thumbnail__placeholder'
		);
		if ( placeholder ) {
			placeholder.remove();
		}
		thumbnail.appendChild( image );
	}

	/**
	 * @param {string[]} titles Pages the cache knows nothing about yet
	 */
	function fetchImages( titles ) {
		// A null entry paints nothing and keeps the next menu from asking
		// again while this request is still out.
		titles.forEach( ( title ) => known.set( title, null ) );
		api = api || new mw.Api();

		api.get( {
			action: 'query',
			formatversion: 2,
			titles,
			prop: 'pageimages',
			piprop: 'thumbnail',
			pithumbsize: thumbSize,
		} )
			.then( ( data ) => {
				const pages = ( data.query && data.query.pages ) || [];

				// Remember the misses too, so typing on does not ask again.
				pages.forEach( ( page ) =>
					known.set(
						page.title,
						( page.thumbnail && page.thumbnail.source ) || null
					)
				);
				scan();
			} )
			.catch( () => {
				// An image that does not arrive is not worth a broken menu.
				// The placeholder icon stays, and the next menu asks again.
				titles.forEach( ( title ) => known.delete( title ) );
			} );
	}

	/** Paint what the cache holds, and ask about the rest. */
	function scan() {
		const wanted = [];

		document
			.querySelectorAll( '.cdx-typeahead-search .cdx-menu-item' )
			.forEach( ( item ) => {
				// The footer entry carries no label and no page behind it.
				const label = item.querySelector(
					'.cdx-menu-item__text__label'
				);
				if ( ! label ) {
					return;
				}

				const title = label.textContent.trim();
				if ( known.has( title ) ) {
					paint( item, known.get( title ) );
				} else {
					wanted.push( title );
				}
			} );

		if ( wanted.length ) {
			fetchImages( wanted );
		}
	}
} )();
