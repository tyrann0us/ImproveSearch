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
    // phpcs:ignore Syde.Classes.DisallowGetterSetter.GetterFound -- MediaWiki Content API
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
            'namespaces' => self::namespaceNames(),
        ]);
    }

    /**
     * The names this wiki writes its namespaces with, so that a French wiki's
     * "Fichier:" is recognised as readily as "File:". Without them the cleaner
     * falls back to a handful of German and English names, and anything else
     * would land in the index.
     *
     * @return array<string, list<string>>
     */
    private static function namespaceNames(): array
    {
        return [
            'media' => array_merge(self::namesOf(NS_FILE), self::namesOf(NS_MEDIA)),
            'category' => self::namesOf(NS_CATEGORY),
            'template' => self::namesOf(NS_TEMPLATE),
        ];
    }

    /**
     * The canonical name, the localised one, and every alias pointing there.
     *
     * @return list<string>
     */
    private static function namesOf(int $namespace): array
    {
        $services = MediaWikiServices::getInstance();
        $language = $services->getContentLanguage();

        $names = [
            (string) $services->getNamespaceInfo()->getCanonicalName($namespace),
            $language->getNsText($namespace),
        ];
        foreach ($language->getNamespaceAliases() as $alias => $index) {
            if ($index === $namespace) {
                $names[] = (string) $alias;
            }
        }

        return array_values(array_unique(array_filter($names)));
    }
}
