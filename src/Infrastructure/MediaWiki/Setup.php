<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki;

use MediaWiki\Content\WikitextContentHandler;
use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;

/**
 * Wires the extension into core configuration.
 *
 * This runs from extension.json's "callback", after LocalSettings.php has been
 * read, so operator settings are visible here and are never overwritten:
 * every switch below bails out as soon as the wiki has an opinion of its own.
 */
final class Setup
{
    /** Search engine name registered under "SearchMappings" in extension.json. */
    private const SEARCH_TYPE = 'ImproveSearch';

    /** Same service list as the stock wikitext handler in MainConfigSchema. */
    private const STOCK_HANDLER_SERVICES = [
        'TitleFactory',
        'ParserFactory',
        'GlobalIdGenerator',
        'LanguageNameUtils',
        'LinkRenderer',
        'MagicWordFactory',
        'ParsoidParserFactory',
    ];

    /**
     * @param array<string, mixed> $credits Extension info, a subset of extension.json
     */
    public static function onRegistration(array $credits, SettingsBuilder $settings): void
    {
        $config = $settings->getConfig();

        if ($config->get('ImproveSearchCleanIndexText')) {
            self::useCleanContentHandler($settings);
        }

        if ($config->get('ImproveSearchSubstringTitles')) {
            self::useSubstringTitleSearch($settings);
        }

        if ($config->get('ImproveSearchSkipUnusedThumbnails')) {
            self::skipUnusedThumbnails($settings);
        }
    }

    /**
     * Hand wikitext pages to our content handler, so that
     * Content::getTextForSearchIndex() returns readable plain text. That single
     * method feeds both the search index (via SearchUpdate) and the snippets on
     * the result page (via RevisionSearchResultTrait::initText()).
     */
    private static function useCleanContentHandler(SettingsBuilder $settings): void
    {
        $handlers = $settings->getConfig()->get(MainConfigNames::ContentHandlers);
        $current = $handlers[CONTENT_MODEL_WIKITEXT] ?? null;

        // Only step in where the stock handler is in place. A wiki that brings
        // its own wikitext handler keeps it.
        $isStock = $current !== null
            && (is_array($current) ? ($current['class'] ?? null) : $current)
                === WikitextContentHandler::class;
        if (!$isStock) {
            return;
        }

        $handlers[CONTENT_MODEL_WIKITEXT] = [
            'class' => CleanWikitextContentHandler::class,
            'services' => is_array($current)
                ? ($current['services'] ?? self::STOCK_HANDLER_SERVICES)
                : self::STOCK_HANDLER_SERVICES,
        ];

        $settings->overrideConfigValue(MainConfigNames::ContentHandlers, $handlers);
    }

    /**
     * Take over the search engine, but only where our subclass fits: it extends
     * SearchMySQL, and only if no other engine has been chosen already.
     */
    private static function useSubstringTitleSearch(SettingsBuilder $settings): void
    {
        $config = $settings->getConfig();

        if ($config->get(MainConfigNames::SearchType) !== null) {
            return;
        }
        if (strtolower((string) $config->get(MainConfigNames::DBtype)) !== 'mysql') {
            return;
        }

        $settings->overrideConfigValue(MainConfigNames::SearchType, self::SEARCH_TYPE);
    }

    /**
     * Special:Search asks PageImages for a thumbnail for every single hit as
     * long as the user option "search-thumbnail-extra-namespaces" is set, and
     * MediaWiki ships it enabled. Outside NS_FILE the thumbnail is then thrown
     * away again, because FullSearchResultWidget only renders one for the
     * namespaces in $wgThumbnailNamespaces.
     *
     * So: switch the option off, but only where it truly buys nothing.
     */
    private static function skipUnusedThumbnails(SettingsBuilder $settings): void
    {
        $config = $settings->getConfig();

        $extra = array_diff($config->get(MainConfigNames::ThumbnailNamespaces), [NS_FILE]);
        if ($extra !== []) {
            // This wiki does show thumbnails outside NS_FILE. Leave it alone.
            return;
        }

        $options = $config->get(MainConfigNames::DefaultUserOptions);
        $options['search-thumbnail-extra-namespaces'] = false;

        $settings->overrideConfigValue(MainConfigNames::DefaultUserOptions, $options);
    }
}
