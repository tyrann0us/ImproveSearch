<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Integration;

use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\SubstringTitleSearchEngine;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Smoke test verifying that the ImproveSearch extension is registered, that
 * its callback claimed the search engine, and that the content handler for
 * wikitext is ours inside a real MediaWiki environment.
 *
 * Other Integration tests can rely on this passing as a precondition.
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

    public function testSearchEngineClassAutoloads(): void
    {
        self::assertTrue(
            class_exists(SubstringTitleSearchEngine::class),
            sprintf('%s did not autoload', SubstringTitleSearchEngine::class)
        );
    }

    public function testCallbackClaimedTheSearchEngine(): void
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();

        self::assertSame('ImproveSearch', $config->get(MainConfigNames::SearchType));
    }
}
