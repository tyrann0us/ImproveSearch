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
    /** Skin whose search suggestions this deals with. */
    private const MOBILE_SKIN = 'minerva';

    /** Module Minerva registers for its Codex typeahead search. */
    private const TYPEAHEAD_MODULE = 'skins.minerva.search';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Load the modules that fetch page images separately. Two places ask for
     * them in the same request as the results: Minerva's typeahead search and
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

        // Asking the module registry rather than the MediaWiki version: the
        // skin brings the Codex typeahead from 1.46 on, and older Minerva
        // has nothing the script could work on.
        if (
            $skin->getSkinName() === self::MOBILE_SKIN
            && $out->getResourceLoader()->isModuleRegistered(self::TYPEAHEAD_MODULE)
        ) {
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
