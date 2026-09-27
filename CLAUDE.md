# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

### PHP

```bash
composer phpcs           # code style check
composer phpcs:fix       # auto-fix style issues
composer phpstan         # static analysis
composer tests           # PHPUnit unit tests
composer tests:coverage  # unit tests + coverage report
composer tests:integration  # integration tests (requires Docker, see below)
```

Run a single test class:

```bash
vendor/bin/phpunit --filter ClassName tests/phpunit/Unit/
```

### JavaScript

```bash
npm test                 # Jest unit tests
npm run test:watch       # Jest in watch mode
npm run test:coverage    # Jest with coverage
npm run test:e2e         # Playwright end-to-end (requires Docker)
npm run lint:js          # ESLint (Node per `engines`: ^20.19, ^22.13 or >= 24)
npm run lint:md          # Markdown lint
```

`lint:js` needs a Node version from `package.json` `engines` (a dependency uses `require()` of an ES module, which older Node 20 releases reject). The local nvm default may be older, so switch first:

```bash
. ~/.nvm/nvm.sh && nvm use 22
```

### Docker (local wiki + MariaDB)

```bash
npm run docker:up   # start MediaWiki 1.44 at localhost:8080 against MariaDB
npm run docker:down # tear down with volumes
```

MariaDB, not SQLite: the search engine extends `SearchMySQL` and the substring matching relies on `CONVERT(… USING utf8mb4) COLLATE utf8mb4_unicode_ci`. There is nothing to exercise on any other backend. `.docker/setup-wiki.sh` installs the wiki, writes the test `LocalSettings.php`, creates one page per claim in the README and runs `rebuildtextindex`.

Integration tests run inside the container; `composer tests:integration` handles this automatically.

---

## Architecture

The extension changes MediaWiki's built-in MySQL search without adding an index, a daemon or a table: what goes into `searchindex`, how title matches are found, and when the mobile overlay fetches its thumbnails.

### Layout

```text
src/
├── Domain/
│   └── WikitextCleaner.php        # Pure regex pipeline: wikitext → readable text
└── Infrastructure/
    └── MediaWiki/                 # MW adapter layer
        ├── Setup.php              # extension.json "callback": claims $wgSearchType,
        │                          #   the wikitext content handler and the
        │                          #   thumbnail user option, each only where free
        ├── HookHandler.php        # 1 hook: BeforePageDisplay
        ├── CleanWikitextContent.php         # extends WikitextContent; overrides
        │                                    #   getTextForSearchIndex()
        ├── CleanWikitextContentHandler.php  # extends WikitextContentHandler
        └── SubstringTitleSearchEngine.php   # extends SearchMySQL; substring titles,
                                             #   deduplication, redirect filtering
```

### Data flow

```text
Index and snippets:
page save → SearchUpdate → Content::getTextForSearchIndex()
       ↓
CleanWikitextContent::getTextForSearchIndex()
       ↓
WikitextCleaner::clean(text, files/templates/keepTemplateParams)
       ↓
searchindex.si_text   (and, through RevisionSearchResultTrait::initText(),
                       the snippet on the result page)

Title matches:
Special:Search        → SubstringTitleSearchEngine::doSearchTitleInDB()
search box typeahead  → SubstringTitleSearchEngine::completionSearchBackend()
       ↓  both:
titleQuery(): page LIKE '%…%' under utf8mb4_unicode_ci, redirects excluded
       ↓  suggestion list only, once title matches run out:
contentMatches() → doSearchTextInDB()   (same query as the results page)

Mobile overlay thumbnails:
HookHandler::onBeforePageDisplay() → ext.improveSearch.deferredThumbnails
       ↓
resources/deferred-thumbnails.js strips piprop/pithumbsize/pilimit from
MobileFrontend's search request, then fills the images in from a second one
```

### Dependency injection

`extension.json` declares the `HookHandler` with the `MainConfig` service. `Setup::onRegistration()` receives the `SettingsBuilder` from the extension callback. `CleanWikitextContent` and `SubstringTitleSearchEngine` are constructed by MediaWiki's ContentHandler and SearchEngineFactory machinery, which pass no services, so they read `MediaWikiServices::getInstance()` directly (no constructor DI possible there). `ExtensionRegistry` is not a service either and is read from its singleton.

---

## Key invariants

**No hard dependency on another extension.** PageImages and MobileFrontend are detected by name through `ExtensionRegistry::isLoaded()`, and the JS reads MobileFrontend's `mw.config` keys behind `|| {}` fallbacks. Nothing calls into either extension's classes, so nothing fatals when they are absent. Keep it that way: a `use` of a foreign class or a bare `mw.mobileFrontend.…` would turn an optional feature into a crash.

**Every switch stands down when the wiki has an opinion.** `Setup` runs from `extension.json`'s `callback`, after `LocalSettings.php`. It claims `$wgSearchType` only when unset and the DB is MySQL, replaces the wikitext content handler only when it is the stock one, and flips `search-thumbnail-extra-namespaces` only when `$wgThumbnailNamespaces` holds nothing beyond `NS_FILE`. Anything else is the operator's decision.

**The cleaner is regular expressions, not a parser.** `WikitextCleaner` may leave crumbs behind on exotic or broken markup. It must never lose visible prose; that is the property the tests guard.

**Only values, never parameter names.** `ImproveSearchKeepTemplateParams` indexes the values of selected template parameters. Indexing the parameter name would be exactly the noise the cleaner exists to remove.

**Title matches are remembered.** `SubstringTitleSearchEngine::$titleMatchIds` is filled by `matchingTitles()` and subtracted in `queryFeatures()`, which core applies to both the text query and its count query. Without it a page matching by name *and* text is listed twice and counted twice.

**Changing an index-shaping setting needs `rebuildtextindex`.** `ImproveSearchCleanIndexText`, `…StripFiles`, `…StripTemplates`, `…KeepTemplateParams` and `…IgnoreRedirects` only affect pages indexed after the change.

**PHPUnit suite is standalone.** It is bootstrapped from `tests/phpunit/bootstrap.php` with hand-written stubs under `tests/phpunit/stubs/`. It does not extend `MediaWikiIntegrationTestCase`. Use `createStub()`, not `createMock()`: PHPUnit 13 emits "no expectations configured" notices for mocks without expectations.

**Hook parameter types.** Hook interface method parameters must be left untyped (PHP LSP constraint). Suppress `Syde.Functions.ArgumentTypeDeclaration.NoArgumentType` via `// phpcs:ignore` on those methods. Return types can be added (covariant).

**MobileFrontend sets its config twice.** `wgMFQueryPropModules` and `wgMFSearchAPIParams` are written again when `mobile.startup` executes, which is after this module runs. `deferred-thumbnails.js` therefore strips them once immediately and once more behind `mw.loader.using( 'mobile.startup' )`.
