<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Unit\Infrastructure\MediaWiki;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\HookHandler;
use MediaWiki\Output\OutputPage;
use MediaWiki\Registration\ExtensionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Skin;

#[CoversClass(HookHandler::class)]
class HookHandlerTest extends TestCase
{
    private const MODULE = 'ext.improveSearch.deferredThumbnails';
    private const VE_MODULE = 'ext.improveSearch.deferredVeThumbnails';

    /**
     * @return array<string, array{bool, string, list<string>}>
     */
    public static function silentProvider(): array
    {
        return [
            'switch off' => [false, 'minerva', ['PageImages', 'MobileFrontend']],
            'other skin' => [true, 'vector', ['PageImages', 'MobileFrontend']],
            'no PageImages' => [true, 'minerva', ['MobileFrontend']],
            'no MobileFrontend' => [true, 'minerva', ['PageImages']],
        ];
    }

    /**
     * The module is only worth loading where all three parts of the problem
     * are present: the overlay skin, PageImages, and MobileFrontend.
     *
     * @param list<string> $loadedExtensions
     */
    #[DataProvider('silentProvider')]
    public function testStaysOutOfTheWay(bool $enabled, string $skinName, array $loadedExtensions): void
    {
        ExtensionRegistry::setInstanceForTesting($loadedExtensions);
        $out = new OutputPage();

        $this->handler($enabled)->onBeforePageDisplay($out, new Skin($skinName));

        self::assertSame([], $out->modules);
    }

    public function testLoadsTheModuleWhenAllThreePartsArePresent(): void
    {
        ExtensionRegistry::setInstanceForTesting(['PageImages', 'MobileFrontend']);
        $out = new OutputPage();

        $this->handler(true)->onBeforePageDisplay($out, new Skin('minerva'));

        self::assertSame([self::MODULE], $out->modules);
    }

    /**
     * @return array<string, array{bool, list<string>}>
     */
    public static function silentVeProvider(): array
    {
        return [
            'switch off' => [false, ['PageImages', 'VisualEditor']],
            'no PageImages' => [true, ['VisualEditor']],
            'no VisualEditor' => [true, ['PageImages']],
        ];
    }

    /**
     * @param list<string> $loadedExtensions
     */
    #[DataProvider('silentVeProvider')]
    public function testStaysOutOfVisualEditorsWay(bool $enabled, array $loadedExtensions): void
    {
        ExtensionRegistry::setInstanceForTesting($loadedExtensions);
        $out = new OutputPage();

        $this->handler($enabled)->onBeforePageDisplay($out, new Skin('vector-2022'));

        self::assertSame([], $out->modules);
    }

    /**
     * Unlike the overlay, the title widgets carry the problem on every skin.
     */
    public function testLoadsTheVisualEditorModuleOnAnySkin(): void
    {
        ExtensionRegistry::setInstanceForTesting(['PageImages', 'VisualEditor']);
        $out = new OutputPage();

        $this->handler(true)->onBeforePageDisplay($out, new Skin('vector-2022'));

        self::assertSame([self::VE_MODULE], $out->modules);
    }

    /**
     * The module is only worth loading for someone who can open the editor.
     */
    public function testSkipsTheVisualEditorModuleForReadersWhoCannotEdit(): void
    {
        ExtensionRegistry::setInstanceForTesting(['PageImages', 'VisualEditor']);
        $out = new OutputPage(false);

        $this->handler(true)->onBeforePageDisplay($out, new Skin('vector-2022'));

        self::assertSame([], $out->modules);
    }

    private function handler(bool $enabled): HookHandler
    {
        return new HookHandler(new HashConfig(['ImproveSearchDeferSearchThumbnails' => $enabled]));
    }
}
