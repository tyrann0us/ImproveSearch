// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * The thumbnails of Minerva's typeahead search, in the browser that renders
 * them. Jest covers the painting on its own; what needs a real wiki is the
 * other half: that the server leaves the images out when it is asked to, and
 * that the menu then ends up with them anyway.
 *
 * The corpus comes from .docker/setup-wiki.sh, where "Weavers Lane" is the
 * one page carrying an uploaded image.
 */

const PAGE_WITH_IMAGE = 'Weavers Lane';

/**
 * @param {import('@playwright/test').Page} page
 * @param {Object<string, string>}          params extra query parameters
 * @return {Promise<Array>} the pages the endpoint answered with
 */
async function titleSearch( page, params ) {
	const response = await page.request.get( '/rest.php/v1/search/title', {
		params: { q: 'weavers', limit: '10', ...params },
	} );

	return ( await response.json() ).pages;
}

/**
 * Open Minerva's search and type, the way a reader does.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          term
 */
async function search( page, term ) {
	await page.setViewportSize( { width: 390, height: 844 } );
	await page.goto( '/wiki/Main_Page?useskin=minerva' );
	await page.locator( 'button#searchIcon' ).click();

	const input = page
		.locator( '.cdx-typeahead-search input[type="search"]' )
		.first();
	await input.waitFor( { state: 'visible' } );
	await input.type( term, { delay: 50 } );
}

test.describe( 'typeahead thumbnails', () => {
	test( 'the endpoint answers without images when it is asked to', async ( {
		page,
	} ) => {
		const withImages = await titleSearch( page, {} );
		const without = await titleSearch( page, { improvesearchthumbs: '0' } );

		// Without the parameter the endpoint behaves as core built it, which
		// is what makes the comparison below worth anything.
		expect(
			withImages.some( ( result ) => result.thumbnail !== null )
		).toBe( true );
		expect( without.every( ( result ) => result.thumbnail === null ) ).toBe(
			true
		);
	} );

	test( 'the menu asks for its list without images', async ( { page } ) => {
		const searches = [];
		page.on( 'request', ( request ) => {
			if ( request.url().includes( '/search/title' ) ) {
				searches.push( request.url() );
			}
		} );

		await search( page, 'weavers' );
		await expect( page.locator( '.cdx-menu-item' ).first() ).toBeVisible();

		expect( searches.length ).toBeGreaterThan( 0 );
		searches.forEach( ( url ) =>
			expect( url ).toContain( 'improvesearchthumbs=0' )
		);
	} );

	test( 'the image arrives afterwards', async ( { page } ) => {
		const listed = [];
		page.on( 'response', async ( response ) => {
			if ( ! response.url().includes( '/search/title' ) ) {
				return;
			}
			const body = await response.json().catch( () => ( { pages: [] } ) );
			listed.push(
				...body.pages.filter(
					( result ) => result.title === PAGE_WITH_IMAGE
				)
			);
		} );

		await search( page, 'weavers' );

		const entry = page
			.locator( '.cdx-menu-item', { hasText: PAGE_WITH_IMAGE } )
			.first();
		await expect( entry ).toBeVisible();
		await expect(
			entry.locator( '.cdx-thumbnail__image' )
		).toHaveAttribute( 'style', /background-image/ );

		// What the menu shows came from the second request: the list the
		// menu was built from carried no image at all.
		expect( listed.length ).toBeGreaterThan( 0 );
		listed.forEach( ( result ) => expect( result.thumbnail ).toBeNull() );
	} );
} );
