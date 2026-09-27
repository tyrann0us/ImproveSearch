<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Unit\Infrastructure\MediaWiki;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\CleanWikitextContent;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanWikitextContent::class)]
class CleanWikitextContentTest extends TestCase
{
    private const REDIRECT = "#REDIRECT [[Stone Bridge]]";

    protected function tearDown(): void
    {
        MediaWikiServices::setInstanceForTesting(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function withConfig(array $config = []): void
    {
        MediaWikiServices::setInstanceForTesting(new HashConfig($config + [
            'ImproveSearchIgnoreRedirects' => true,
            'ImproveSearchStripFiles' => true,
            'ImproveSearchStripTemplates' => true,
            'ImproveSearchKeepTemplateParams' => [],
        ]));
    }

    public function testIndexesReadableText(): void
    {
        self::withConfig();
        $content = new CleanWikitextContent(
            "{{Cleanup}}[[File:X.jpg|thumb|A caption.]]Visible text."
        );

        self::assertSame('Visible text.', $content->getTextForSearchIndex());
    }

    public function testPassesTheSwitchesThrough(): void
    {
        self::withConfig([
            'ImproveSearchStripFiles' => false,
            'ImproveSearchStripTemplates' => false,
            'ImproveSearchKeepTemplateParams' => ['Address' => ['street']],
        ]);
        $content = new CleanWikitextContent('[[File:X.jpg|thumb|c]]{{Address|street=Wharf Road}}');

        $indexed = $content->getTextForSearchIndex();

        self::assertStringContainsString('X.jpg', $indexed);
        self::assertStringContainsString('Wharf Road', $indexed);
    }

    /**
     * A redirect is a signpost, not an article. Indexing the target's name
     * would make it a second hit for everything the target already matches.
     */
    public function testRedirectIsIndexedEmpty(): void
    {
        self::withConfig();
        $content = new CleanWikitextContent(self::REDIRECT, 'Stone Bridge');

        self::assertSame('', $content->getTextForSearchIndex());
    }

    public function testRedirectIsIndexedWhenTheSwitchIsOff(): void
    {
        self::withConfig(['ImproveSearchIgnoreRedirects' => false]);
        $content = new CleanWikitextContent(self::REDIRECT, 'Stone Bridge');

        self::assertSame('REDIRECT Stone Bridge', $content->getTextForSearchIndex());
    }
}
