<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for standalone (non-MediaWiki) tests.
 *
 * Loads the Composer autoloader and defines the minimal set of MediaWiki
 * constants and stub classes that the extension code references.
 */

// 1. Composer autoloader (loads extension classes via PSR-4).
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// 2. MediaWiki constants.
if (!defined('CONTENT_MODEL_WIKITEXT')) {
    define('CONTENT_MODEL_WIKITEXT', 'wikitext');
}
if (!defined('NS_MAIN')) {
    define('NS_MAIN', 0);
}
if (!defined('NS_FILE')) {
    define('NS_FILE', 6);
}
if (!defined('NS_MEDIA')) {
    define('NS_MEDIA', -2);
}
if (!defined('NS_TEMPLATE')) {
    define('NS_TEMPLATE', 10);
}
if (!defined('NS_CATEGORY')) {
    define('NS_CATEGORY', 14);
}
if (!defined('NS_SPECIAL')) {
    define('NS_SPECIAL', -1);
}

// 3. Load stub files that define MW classes in their proper namespaces.
require_once __DIR__ . '/stubs/global-classes.php';
require_once __DIR__ . '/stubs/mediawiki-namespaced.php';
