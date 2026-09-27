// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * End-to-end checks against the docker wiki built by .docker/setup-wiki.sh.
 * The corpus there is small on purpose: one page per claim in the README.
 */

/**
 * @param {import('@playwright/test').Page} page
 * @param {string}                          term
 * @return {Promise<string[]>} the suggested page titles, in order
 */
async function suggestions( page, term ) {
	const response = await page.request.get( '/api.php', {
		params: {
			action: 'opensearch',
			format: 'json',
			formatversion: '2',
			search: term,
		},
	} );
	const body = await response.json();
	return body[ 1 ];
}

test.describe( 'suggestion list', () => {
	test( 'matches the term anywhere in the title', async ( { page } ) => {
		const titles = await suggestions( page, 'bridge' );

		expect( titles ).toContain( 'Bridge Street' );
		expect( titles ).toContain( 'Stone Bridge' );
		// Matches at the start of the title come first.
		expect( titles.indexOf( 'Bridge Street' ) ).toBeLessThan(
			titles.indexOf( 'Stone Bridge' )
		);
	} );

	test( 'folds case and umlauts', async ( { page } ) => {
		expect( await suggestions( page, 'muller' ) ).toContain(
			'Müller Mill'
		);
	} );

	test( 'leaves redirects out', async ( { page } ) => {
		expect( await suggestions( page, 'Old Bridge' ) ).not.toContain(
			'Old Bridge'
		);
	} );

	test( 'tops up with content matches once titles run out', async ( {
		page,
	} ) => {
		// No title contains the address; the kept template parameters do.
		expect( await suggestions( page, 'Wharf Road 7' ) ).toContain(
			'Warehouse'
		);
	} );
} );

test.describe( 'Special:Search', () => {
	test( 'snippets carry no wikitext markup', async ( { page } ) => {
		await page.goto( '/index.php?search=Warehouse&fulltext=1' );

		const results = page.locator( '.mw-search-results' );
		await expect( results ).toBeVisible();

		const text = await results.innerText();
		expect( text ).not.toContain( '[[' );
		expect( text ).not.toContain( '{{' );
		expect( text ).not.toContain( '.jpg' );
	} );

	test( 'the file name of an embedded image is not a search term', async ( {
		page,
	} ) => {
		await page.goto( '/index.php?search=Boehme&fulltext=1' );

		await expect( page.locator( '.mw-search-nonefound' ) ).toBeVisible();
	} );

	test( 'no page is listed twice', async ( { page } ) => {
		await page.goto( '/index.php?search=Stone+Bridge&fulltext=1' );

		const links = page.locator(
			'.mw-search-result-heading a[title="Stone Bridge"]'
		);
		await expect( links ).toHaveCount( 1 );
	} );
} );
