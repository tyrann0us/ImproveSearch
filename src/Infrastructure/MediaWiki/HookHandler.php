<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki;

use MediaWiki\Config\Config;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * Hook handlers that only concern the presentation layer.
 */
class HookHandler implements BeforePageDisplayHook
{
    /** Core module behind the Codex typeahead, in whichever skin has one. */
    private const TYPEAHEAD_MODULE = 'mediawiki.skinning.typeaheadSearch';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Load the modules that fetch page images separately. Two places ask for
     * them in the same request as the results: the typeahead search and
     * VisualEditor's title widgets. Both need PageImages as the source of the
     * thumbnails.
     *
     * ExtensionRegistry is not a service in MediaWikiServices, so it is read
     * from its singleton rather than injected.
     *
     * @param \MediaWiki\Output\OutputPage $out
     * @param \Skin $skin
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- MediaWiki hook interface signature
    public function onBeforePageDisplay($out, $skin): void
    {
        if (!$this->config->get('ImproveSearchDeferSearchThumbnails')) {
            return;
        }

        $registry = ExtensionRegistry::getInstance();
        if (!$registry->isLoaded('PageImages')) {
            return;
        }

        // Minerva and Vector 2022 share the core typeahead, so what counts
        // is whether this MediaWiki has that search, not which skin runs.
        if ($out->getResourceLoader()->isModuleRegistered(self::TYPEAHEAD_MODULE)) {
            $out->addModules(['ext.improveSearch.deferredThumbnails']);
        }

        // The title widgets carry the problem on every skin, but only for
        // readers who can open the editor at all. For anyone else the module
        // would sit on the page waiting for a hook that never fires.
        if ($registry->isLoaded('VisualEditor') && $out->getAuthority()->isAllowed('edit')) {
            $out->addModules(['ext.improveSearch.deferredVeThumbnails']);
        }
    }
}
