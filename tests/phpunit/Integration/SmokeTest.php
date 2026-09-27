<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Integration;

use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\CleanWikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Verifies that the extension is registered and that a page built through
 * MediaWiki's own content-handler factory reaches the cleaner.
 *
 * The search engine is not asserted here. MediaWikiIntegrationTestCase pins
 * $wgSearchType to SearchEngineDummy for test isolation, so whatever the
 * callback claimed is gone by the time a test runs. Special:Search and the
 * suggestion list are covered by the Playwright suite instead.
 */
#[CoversNothing]
class SmokeTest extends MediaWikiIntegrationTestCase
{
    public function testExtensionRegistered(): void
    {
        self::assertTrue(
            ExtensionRegistry::getInstance()->isLoaded('ImproveSearch'),
            'ImproveSearch extension is not loaded. Check LocalSettings.php.'
        );
    }

    /**
     * The handler exists so that every wikitext page MediaWiki builds comes
     * back as content whose search text is readable prose.
     */
    public function testWikitextPagesReachTheCleaner(): void
    {
        $handler = MediaWikiServices::getInstance()
            ->getContentHandlerFactory()
            ->getContentHandler(CONTENT_MODEL_WIKITEXT);

        $content = $handler->unserializeContent(
            "{{Cleanup}}[[File:X.jpg|thumb|A caption.]]Visible text."
        );

        self::assertInstanceOf(CleanWikitextContent::class, $content);
        self::assertSame('Visible text.', $content->getTextForSearchIndex());
    }
}
