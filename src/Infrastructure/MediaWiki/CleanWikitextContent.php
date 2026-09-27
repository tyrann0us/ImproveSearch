<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki;

use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\ImproveSearch\Domain\WikitextCleaner;
use MediaWiki\MediaWikiServices;

/**
 * Wikitext whose search representation is readable plain text.
 *
 * Everything else about the page is untouched; only the one method that both
 * the index writer and the snippet builder call is overridden.
 *
 * Content objects are built by MediaWiki's ContentHandler machinery, which
 * passes no services, so the configuration is read from the service container
 * here rather than injected.
 */
class CleanWikitextContent extends WikitextContent
{
    public function getTextForSearchIndex(): string
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();

        // A redirect is a signpost, not an article. Its only content is the
        // name of the target, which would make it a second hit for everything
        // the target already matches.
        if ($config->get('ImproveSearchIgnoreRedirects') && $this->getRedirectTarget() !== null) {
            return '';
        }

        return WikitextCleaner::clean($this->getText(), [
            'files' => (bool) $config->get('ImproveSearchStripFiles'),
            'templates' => (bool) $config->get('ImproveSearchStripTemplates'),
            'keepTemplateParams' => (array) $config->get('ImproveSearchKeepTemplateParams'),
        ]);
    }
}
