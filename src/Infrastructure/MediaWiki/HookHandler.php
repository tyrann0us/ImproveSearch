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
    /** Skin that carries MobileFrontend's search overlay. */
    private const OVERLAY_SKIN = 'minerva';

    /** Both have to be installed, or there is nothing to defer. */
    private const REQUIRED_EXTENSIONS = ['PageImages', 'MobileFrontend'];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Load the module that fetches the search overlay's page images
     * separately. Only where all three parts of the problem are present: the
     * overlay itself, PageImages as the source of the thumbnails, and
     * MobileFrontend, which welds the two together without asking.
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

        if ($skin->getSkinName() !== self::OVERLAY_SKIN) {
            return;
        }

        $registry = ExtensionRegistry::getInstance();
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!$registry->isLoaded($extension)) {
                return;
            }
        }

        $out->addModules(['ext.improveSearch.deferredThumbnails']);
    }
}
