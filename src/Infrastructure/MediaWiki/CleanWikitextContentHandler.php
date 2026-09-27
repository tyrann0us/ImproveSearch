<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki;

use MediaWiki\Content\WikitextContentHandler;

/**
 * Stock wikitext handler, but it hands out CleanWikitextContent.
 *
 * Registered for CONTENT_MODEL_WIKITEXT from Setup::useCleanContentHandler().
 */
class CleanWikitextContentHandler extends WikitextContentHandler
{
    protected function getContentClass(): string
    {
        return CleanWikitextContent::class;
    }
}
