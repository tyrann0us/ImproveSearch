# ImproveSearch

[![PHP QA](https://github.com/tyrann0us/ImproveSearch/actions/workflows/quality-assurance-php.yml/badge.svg)](https://github.com/tyrann0us/ImproveSearch/actions/workflows/quality-assurance-php.yml)
[![JS QA](https://github.com/tyrann0us/ImproveSearch/actions/workflows/quality-assurance-js.yml/badge.svg)](https://github.com/tyrann0us/ImproveSearch/actions/workflows/quality-assurance-js.yml)
[![Integration Tests](https://github.com/tyrann0us/ImproveSearch/actions/workflows/integration-tests.yml/badge.svg)](https://github.com/tyrann0us/ImproveSearch/actions/workflows/integration-tests.yml)
[![E2E Tests](https://github.com/tyrann0us/ImproveSearch/actions/workflows/e2e-tests.yml/badge.svg)](https://github.com/tyrann0us/ImproveSearch/actions/workflows/e2e-tests.yml)
[![codecov](https://codecov.io/gh/tyrann0us/ImproveSearch/graph/badge.svg)](https://codecov.io/gh/tyrann0us/ImproveSearch)

Small fixes to MediaWiki's built-in MySQL search. No Elasticsearch, no search daemon, no extra database table. The extension changes what goes into the existing `searchindex` table, how title matches are found, how the result page is assembled, and when the mobile search overlay asks for its thumbnails.

Written for wikis on shared hosting where CirrusSearch is not an option, and small enough to read in one sitting.

Each section below describes one problem, the fix, and the setting that turns it off. The [configuration table](#configuration) lists all of them together.

## Table of contents

* [Installation](#installation)
* [Searching the whole page title](#searching-the-whole-page-title)
* [Suggestions beyond the page name](#suggestions-beyond-the-page-name)
* [No page listed twice](#no-page-listed-twice)
* [Redirects](#redirects)
* [A search index without wikitext](#a-search-index-without-wikitext)
* [Keeping selected template parameters](#keeping-selected-template-parameters)
* [Thumbnails the result page never shows](#thumbnails-the-result-page-never-shows)
* [Thumbnails after the mobile result list](#thumbnails-after-the-mobile-result-list)
* [Configuration](#configuration)
* [Development](#development)
* [Scope and limits](#scope-and-limits)
* [Requirements](#requirements)
* [Copyright and license](#copyright-and-license)
* [Contributing](#contributing)

## Installation

1. Place this directory into `extensions/ImproveSearch`.
2. Add to `LocalSettings.php`:

```php
wfLoadExtension( 'ImproveSearch' );
```

Then rebuild the index once, so existing pages are reindexed from the cleaned text:

```bash
php maintenance/run.php rebuildtextindex
```

That is the only step that touches stored data. Every edit from then on indexes itself correctly. Run it again after changing any setting that shapes the index, which the sections below point out where it applies.

## Searching the whole page title

Core answers the typeahead under the search box with a prefix search, so typing `bridge` never suggests `Stone Bridge`. The title hits on `Special:Search` have a different problem with the same effect: core matches them word-wise against `searchindex.si_title`, so `Baker` did not find `Bakery Row`.

Both now match the term anywhere in the title, with matches at the start of the title ranked first, then matches further in, then alphabetically. The comparison runs under `utf8mb4_unicode_ci`, which also folds case and umlauts: `Zurich` finds `Zürich`, `Muller` finds `Müller`.

This replaces the search engine, so the extension claims `$wgSearchType`. It does that only when no engine has been chosen yet and the database is MySQL or MariaDB, because the engine subclasses `SearchMySQL`. Set `$wgImproveSearchSubstringTitles = false;` to leave core's search engine in place.

## Suggestions beyond the page name

The suggestion list under the search box can only ever offer what is spelled out in a page name. A house whose address lives in a template parameter, or any page whose subject is named only in its text, stays invisible there however well the full-text index knows it.

So once the title matches run out, the list is topped up from the text index, using the same query, in the same order, that produces the text hits on `Special:Search`. The two lists therefore agree for any query, which matters more than it sounds: a suggestion list that ranks differently from the page it leads to makes the wiki feel unpredictable.

It is also strictly additive. Title matches keep their places and their order, and the extra query does not run at all while they still fill the list, which is the normal case for the short prefixes people type first.

| Typed              | Suggested                                                     |
|--------------------|---------------------------------------------------------------|
| `bridge`           | `Bridge Street`, `Stone Bridge`, … (titles only, unchanged)   |
| `Main Street 12`   | `Old Town Hall`, no title contains it, the address does       |
| `"Wharf Road 7"`   | `Warehouse`, quoting works here too                           |

Ordering is MySQL's relevance ranking, so a loose multi-word query can bury the page you meant. Quoting fixes that, and fixes it identically in both places.

Set `$wgImproveSearchSuggestContentMatches = false;` to keep the suggestion list strictly about page names. It needs `$wgImproveSearchSubstringTitles`, since the same engine answers both.

## No page listed twice

`Special:Search` asks the engine for title matches and for text matches, then prints both blocks. A page whose name *and* text match the term therefore appears twice, one entry a few lines above the other, and the count at the top of the page counts it twice as well. Substring title matching makes this far more common than it was with core's word-wise title search.

The engine remembers which pages it returned as title matches and leaves them out of the text query, including its count query. Each page is listed once, in the title block where it ranks highest.

This is also what made the suggestion list and the results page agree for every query: the suggestion list had always been deduplicated, the results page had not. There is no setting for it; deduplication is part of the engine and goes away with `$wgImproveSearchSubstringTitles`.

## Redirects

A redirect is a signpost, not a result. Listing `Old Bridge` next to `Stone Bridge` offers the reader the same article twice under two names, and the redirect carries no content of its own.

By default redirects are therefore left out of both lists: the title search skips them in SQL, and redirect pages are indexed with empty text so they cannot turn up as full-text hits either. Note the second half. After changing `$wgImproveSearchIgnoreRedirects` you need `rebuildtextindex` for it to take effect on existing pages.

Wikis that use redirects as genuine alternative names, and would rather see them offered, set `$wgImproveSearchIgnoreRedirects = false;`.

## A search index without wikitext

`SearchUpdate` writes `Content::getTextForSearchIndex()` into `searchindex.si_text`, and for wikitext that is the unprocessed source. `RevisionSearchResultTrait::initText()` reads the same unprocessed source for the snippet under each hit. One cause, two symptoms:

* Once the non-word characters are stripped, the words from file names and template parameters stay behind as search terms. `sortkey` finds every page using a template with that parameter, and the file name of an embedded photo makes its page a hit for words that appear nowhere in the article.
* Snippets show the markup. `[[File:Photo.jpg|thumb|A caption]]` turns up in the middle of the two lines under a result.

Both are fixed in one place. The extension replaces the content handler for `CONTENT_MODEL_WIKITEXT` with one whose `getTextForSearchIndex()` returns readable running text: no embeds, no template calls, no categories, no references, no table or list markers. Links keep their visible part, semantic annotations `[[Property::Value]]` keep their value.

It steps in only where `$wgContentHandlers[CONTENT_MODEL_WIKITEXT]` is still the stock handler. A wiki that brings its own keeps it.

| Setting                                   | Effect                                                               |
|-------------------------------------------|----------------------------------------------------------------------|
| `$wgImproveSearchCleanIndexText = false;` | Index and snippets go back to raw wikitext.                          |
| `$wgImproveSearchStripFiles = false;`     | File and media embeds stay in the indexed text, file names included. |
| `$wgImproveSearchStripTemplates = false;` | Template calls and their parameters stay in the indexed text.        |

Run `rebuildtextindex` after changing any of the three.

## Keeping selected template parameters

Stripping template calls costs something: a fact that lives only in a parameter disappears from the index. Infobox templates that set semantic properties are the usual case, where the value is real data that is simply never rendered as text.

```php
$wgImproveSearchKeepTemplateParams = [ 'Address' => [ 'street', 'number' ] ];
```

Now `{{Address|street=Main Street|number=12|sortkey=12}}` contributes `Main Street 12` to the index. Only the values, never the parameter names: indexing `sortkey` would be exactly the noise this extension removes. Parameters not on the list, `sortkey` here, stay out too.

Names match regardless of case, underscores, and a localised or canonical namespace prefix, so `Address`, `template:address` and `Vorlage:Address` all mean the same template. Parser functions such as `{{#set:…}}` are never kept.

The values land next to each other in the index, so a quoted search is exact. `"Main Street 12"` returns the one page at that address, while the unquoted `Main Street 12` matches every page containing all three words and leaves the ordering to MySQL's relevance ranking. How well the address page does there depends on how distinctive the street name is. `Wharf Road 7` puts it first, `New Street 4` does not, because "New" and "Street" appear all over the wiki.

The default is an empty list, which keeps nothing. Run `rebuildtextindex` after changing this setting.

## Thumbnails the result page never shows

`FullSearchResultWidget::generateFileHtml()` asks PageImages for a thumbnail for every hit as soon as the user option `search-thumbnail-extra-namespaces` is set, and MediaWiki ships it enabled. Outside `NS_FILE` the thumbnail is then thrown away again, because the widget only renders one for the namespaces in `$wgThumbnailNamespaces`. On a wiki of 3,800 pages that was about 0.25 s per hit, roughly seven seconds for a page of twenty results.

There is a second half to this worth naming. `DefaultPreferencesFactory` registers the checkbox in `Special:Preferences` only when `$wgThumbnailNamespaces` holds more than `NS_FILE`. Under the default configuration the preference is therefore invisible and no user can switch it off, while its default value of `true` keeps triggering the lookup on every hit.

The extension turns the option off by default, but only when `$wgThumbnailNamespaces` lists nothing beyond `NS_FILE`, which is exactly the configuration in which no user can reach the checkbox and no thumbnail is ever rendered. Where the checkbox does exist, the wiki has decided it wants thumbnails, and the extension leaves them alone.

Set `$wgImproveSearchSkipUnusedThumbnails = false;` to keep core's default in every configuration.

## Thumbnails after the mobile result list

MobileFrontend asks for the page images in the same request as the results, so nothing appears until the slowest image is known. On a cold cache a fifteen-hit search took between two and eight seconds instead of a third of one, because MediaWiki fetches the file data from the foreign repository before it answers at all.

The extension drops the image parameters from that request. The list appears with the placeholder icons MobileFrontend renders anyway, and a second request fills the images in. The script only loads on the Minerva skin and only when both PageImages and MobileFrontend are installed.

Set `$wgImproveSearchDeferSearchThumbnails = false;` to let MobileFrontend fetch the images with the results again.

## Configuration

All switches default to on, except the keep list, which defaults to empty. Each can be turned off on its own.

| Variable                                | Default | Effect                                                                            |
|-----------------------------------------|---------|-----------------------------------------------------------------------------------|
| `$wgImproveSearchSubstringTitles`       | `true`  | Match the term anywhere in the title. Sets `$wgSearchType`.                       |
| `$wgImproveSearchSuggestContentMatches` | `true`  | Top up the suggestion list with text matches once title matches run out.          |
| `$wgImproveSearchIgnoreRedirects`       | `true`  | Keep redirects out of results and suggestions.                                    |
| `$wgImproveSearchCleanIndexText`        | `true`  | Feed index and snippets with plain text. Replaces the wikitext content handler.   |
| `$wgImproveSearchStripFiles`            | `true`  | Drop file and media embeds from the indexed text.                                 |
| `$wgImproveSearchStripTemplates`        | `true`  | Drop template calls and their parameters from the indexed text.                   |
| `$wgImproveSearchKeepTemplateParams`    | `[]`    | Exceptions to the line above: templates whose parameter values stay in the index. |
| `$wgImproveSearchSkipUnusedThumbnails`  | `true`  | Stop the pointless PageImages lookup per hit.                                     |
| `$wgImproveSearchDeferSearchThumbnails` | `true`  | Fetch the mobile overlay's thumbnails after the result list, not with it.         |

The extension never overrules a wiki that has an opinion of its own. It stays out of the way when:

* `$wgSearchType` is already set, including by another extension such as TitleKey, which registers its own engine the same way;
* the database is not MySQL or MariaDB, because the engine subclasses `SearchMySQL`;
* `$wgContentHandlers[CONTENT_MODEL_WIKITEXT]` is not the stock handler;
* `$wgThumbnailNamespaces` lists namespaces beyond `NS_FILE`.

Nothing here requires another extension. PageImages and MobileFrontend are detected by name through `ExtensionRegistry`, never called, so the extension behaves the same whether they are installed or not.

## Development

```bash
composer install
npm install

composer phpcs      # code style (Syde-Extra)
composer phpstan    # static analysis, level 8
composer tests      # PHPUnit, tests/phpunit/Unit
npm test            # Jest, tests/jest
```

The unit suite runs without MediaWiki: `tests/phpunit/bootstrap.php` loads hand-written stubs from `tests/phpunit/stubs/`, so the text cleaner and the setup logic are testable in isolation.

A throwaway wiki for the integration and end-to-end suites comes from Docker:

```bash
npm run docker:up            # MediaWiki 1.44 + MariaDB at localhost:8080
composer tests:integration   # PHPUnit inside the container
npm run test:e2e             # Playwright against the running wiki
npm run docker:down
```

It is MariaDB rather than SQLite on purpose: the engine extends `SearchMySQL`, so no other backend exercises it.

On a live wiki, the one query that shows the index is clean:

```bash
# must return 0
php maintenance/run.php sql --query 'SELECT SUM(si_text LIKE "%.jpg%") FROM searchindex'
```

## Scope and limits

* Full-text search over page content is untouched. It stays MySQL fulltext, with everything that implies: whole words only unless you append `*`, and `ft_min_word_len` still applies.
* The cleaner is regular expressions, not a parser. Parsing every revision on save would cost far more than indexing is worth, and the output only has to be good enough for a word index and a two-line snippet. Exotic or broken markup can leave crumbs behind; it can never lose visible prose.
* Content that exists only inside template parameters becomes unfindable. That is the point of stripping templates, but it is a real trade-off on wikis that keep facts in infobox parameters and nowhere else. Use `$wgImproveSearchKeepTemplateParams` for those, or turn `$wgImproveSearchStripTemplates` off entirely.
* The title search is a full table scan. `LIKE '%…%'` cannot use an index, and neither can the case folding. At a few thousand pages that is a millisecond or two. Wikis in the hundreds of thousands of pages want CirrusSearch, not this.

## Requirements

MediaWiki 1.44 or later, MySQL or MariaDB. Developed and tested against 1.44 with Semantic MediaWiki.

## Copyright and license

This package is [open-source software](https://opensource.org/license/MIT) distributed under the terms of the MIT License. See [LICENSE](./LICENSE) for the full text.

## Contributing

Feedback, bug reports and pull requests are welcome. To start a discussion, [open an issue](https://github.com/tyrann0us/ImproveSearch/issues).
