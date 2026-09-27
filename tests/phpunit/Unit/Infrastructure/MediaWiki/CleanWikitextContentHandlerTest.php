<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Unit\Infrastructure\MediaWiki;

use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\CleanWikitextContent;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\CleanWikitextContentHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanWikitextContentHandler::class)]
class CleanWikitextContentHandlerTest extends TestCase
{
    /**
     * The handler exists for one reason: every wikitext page it builds has to
     * be a CleanWikitextContent, or nothing reaches the cleaner.
     */
    public function testHandsOutCleanWikitextContent(): void
    {
        $handler = new class extends CleanWikitextContentHandler {
            public function contentClass(): string
            {
                return $this->getContentClass();
            }
        };

        self::assertSame(CleanWikitextContent::class, $handler->contentClass());
    }
}
