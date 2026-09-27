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

    private function handler(bool $enabled): HookHandler
    {
        return new HookHandler(new HashConfig(['ImproveSearchDeferSearchThumbnails' => $enabled]));
    }
}
