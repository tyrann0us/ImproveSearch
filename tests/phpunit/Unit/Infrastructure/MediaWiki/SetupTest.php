<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Unit\Infrastructure\MediaWiki;

use MediaWiki\Config\HashConfig;
use MediaWiki\Content\WikitextContentHandler;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\CleanWikitextContentHandler;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\Setup;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\SubstringTitleSearchEngine;
use MediaWiki\MainConfigNames;
use MediaWiki\Settings\SettingsBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every switch has to bail out as soon as the wiki has an opinion of its own,
 * which is the whole contract of running from extension.json's callback.
 */
#[CoversClass(Setup::class)]
class SetupTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private static function builder(array $settings = []): SettingsBuilder
    {
        return new SettingsBuilder(new HashConfig($settings + [
            'ImproveSearchCleanIndexText' => true,
            'ImproveSearchSubstringTitles' => true,
            'ImproveSearchSkipUnusedThumbnails' => true,
            MainConfigNames::ContentHandlers => [
                CONTENT_MODEL_WIKITEXT => WikitextContentHandler::class,
            ],
            MainConfigNames::DBtype => 'mysql',
            MainConfigNames::SearchType => null,
            MainConfigNames::ThumbnailNamespaces => [NS_FILE],
            MainConfigNames::DefaultUserOptions => ['search-thumbnail-extra-namespaces' => true],
        ]));
    }

    public function testTakesOverEverythingOnAStockWiki(): void
    {
        $builder = self::builder();

        Setup::onRegistration([], $builder);

        $handlers = $builder->overrides[MainConfigNames::ContentHandlers];
        self::assertSame(
            CleanWikitextContentHandler::class,
            $handlers[CONTENT_MODEL_WIKITEXT]['class']
        );
        self::assertSame('ImproveSearch', $builder->overrides[MainConfigNames::SearchType]);
        self::assertFalse(
            $builder->overrides[MainConfigNames::DefaultUserOptions]['search-thumbnail-extra-namespaces']
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function switchedOffProvider(): array
    {
        return [
            'clean index text off' => [['ImproveSearchCleanIndexText' => false]],
            'substring titles off' => [['ImproveSearchSubstringTitles' => false]],
            'skip unused thumbnails off' => [['ImproveSearchSkipUnusedThumbnails' => false]],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('switchedOffProvider')]
    public function testSwitchedOffChangesNothing(array $settings): void
    {
        $builder = self::builder($settings);

        Setup::onRegistration([], $builder);

        $keys = [
            'ImproveSearchCleanIndexText' => MainConfigNames::ContentHandlers,
            'ImproveSearchSubstringTitles' => MainConfigNames::SearchType,
            'ImproveSearchSkipUnusedThumbnails' => MainConfigNames::DefaultUserOptions,
        ];
        self::assertArrayNotHasKey($keys[array_key_first($settings)], $builder->overrides);
    }

    public function testKeepsAForeignWikitextContentHandler(): void
    {
        $builder = self::builder([
            MainConfigNames::ContentHandlers => [CONTENT_MODEL_WIKITEXT => 'Some\\Other\\Handler'],
        ]);

        Setup::onRegistration([], $builder);

        self::assertArrayNotHasKey(MainConfigNames::ContentHandlers, $builder->overrides);
    }

    public function testKeepsTheStockHandlersServiceList(): void
    {
        $builder = self::builder([
            MainConfigNames::ContentHandlers => [
                CONTENT_MODEL_WIKITEXT => [
                    'class' => WikitextContentHandler::class,
                    'services' => ['TitleFactory'],
                ],
            ],
        ]);

        Setup::onRegistration([], $builder);

        $handlers = $builder->overrides[MainConfigNames::ContentHandlers];
        self::assertSame(['TitleFactory'], $handlers[CONTENT_MODEL_WIKITEXT]['services']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function foreignSearchProvider(): array
    {
        return [
            'another engine got there first' => [[MainConfigNames::SearchType => 'TitleKey']],
            'not MySQL' => [[MainConfigNames::DBtype => 'postgres']],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('foreignSearchProvider')]
    public function testLeavesTheSearchEngineAlone(array $settings): void
    {
        $builder = self::builder($settings);

        Setup::onRegistration([], $builder);

        self::assertArrayNotHasKey(MainConfigNames::SearchType, $builder->overrides);
        self::assertTrue(class_exists(SubstringTitleSearchEngine::class));
    }

    public function testLeavesThumbnailsAloneWhereTheWikiShowsThem(): void
    {
        $builder = self::builder([
            MainConfigNames::ThumbnailNamespaces => [NS_FILE, NS_MAIN],
        ]);

        Setup::onRegistration([], $builder);

        self::assertArrayNotHasKey(MainConfigNames::DefaultUserOptions, $builder->overrides);
    }
}
