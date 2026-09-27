<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Domain;

/**
 * Turns wikitext into the running text a reader actually sees.
 *
 * Markup goes away: file and media embeds, template calls with their
 * parameters, categories, references, table and list markers. Links keep their
 * visible part, semantic annotations [[Property::Value]] keep their value.
 *
 * This is deliberately a set of regular expressions rather than a parser run.
 * Parsing every revision on save would be far more expensive than indexing is
 * worth, and the result only has to be good enough for a word index and a
 * two-line snippet. The trade-off: exotic or broken markup can leave crumbs
 * behind. It can never lose visible prose, which is the property that matters.
 */
final class WikitextCleaner
{
    /** Namespaces whose links are embeds or bookkeeping, not readable text. */
    private const MEDIA_NAMESPACES = 'Datei|Bild|File|Image|Media|Medium';
    private const CATEGORY_NAMESPACES = 'Kategorie|Category';

    /** Tags whose content is markup, data or a file list rather than prose. */
    private const TAGS_WITH_CONTENT = 'ref|gallery|imagemap|maplink|mapframe|math|chem|score|graph|'
        . 'syntaxhighlight|source|pre|nowiki|templatestyles|timeline|categorytree|dpl|indicator';

    /** Guard against pathological nesting; real pages need a handful of rounds. */
    private const MAX_ROUNDS = 20;

    /**
     * @param string $text Raw wikitext
     * @param array{
     *     files?: bool,
     *     templates?: bool,
     *     keepTemplateParams?: array<string, list<string>>
     * } $options Both flags default to true, the keep list defaults to empty.
     *     Passing them explicitly keeps this class usable without MediaWiki,
     *     which is what the unit test does.
     */
    // phpcs:ignore Syde.Functions.FunctionLength.TooLong -- one linear pipeline; splitting it would hide the order the steps depend on
    public static function clean(string $text, array $options = []): string
    {
        $stripFiles = $options['files'] ?? true;
        $stripTemplates = $options['templates'] ?? true;
        $keep = self::normaliseKeepList($options['keepTemplateParams'] ?? []);

        // Comments.
        $text = (string) preg_replace('/<!--.*?-->/s', ' ', $text);

        // Tags with content (<ref>…</ref>) and their empty form (<ref … />).
        $text = (string) preg_replace(
            '#<(?:' . self::TAGS_WITH_CONTENT . ')\b[^>]*?/\s*>#is',
            ' ',
            $text
        );
        $text = (string) preg_replace(
            '#<(' . self::TAGS_WITH_CONTENT . ')\b[^>]*>.*?</\s*\1\s*>#is',
            ' ',
            $text
        );

        // Template calls and parser functions, innermost first.
        if ($stripTemplates || $keep !== []) {
            $text = self::stripTemplates($text, $stripTemplates, $keep);
        }

        // External links before wiki links: a file caption may contain one, and
        // its closing bracket would otherwise hide the embed around it.
        // [https://… label] -> label
        $text = (string) preg_replace(
            '/\[(?:https?:|ftp:|mailto:|\/\/)[^\s\[\]]*\s+([^\[\]]*)\]/i',
            ' $1 ',
            $text
        );
        $text = (string) preg_replace('/\[(?:https?:|ftp:|mailto:|\/\/)[^\s\[\]]*\]/i', ' ', $text);
        // Bare addresses. Square brackets are not part of them, or the pattern
        // would swallow the end of a surrounding embed.
        $text = (string) preg_replace('#(?:https?://|ftp://)[^\s\[\]|]+#i', ' ', $text);

        // Wiki links, innermost first.
        $text = self::stripLinks($text, $stripFiles);

        // Table markup.
        $text = (string) preg_replace('/^\s*\{\|.*$/m', ' ', $text);
        $text = (string) preg_replace('/^\s*\|\}.*$/m', ' ', $text);
        $text = (string) preg_replace('/^\s*[|!]-.*$/m', ' ', $text);
        // Cells: cut the attributes in front of the last pipe.
        $text = (string) preg_replace('/^\s*[|!]\s*(?:[^|\[\]{}]*\|(?!\|))?/m', ' ', $text);
        $text = str_replace('||', ' ', $text);

        // Headings, lists, rules, behaviour switches.
        $text = (string) preg_replace('/^\s*=+\s*(.*?)\s*=+\s*$/m', '$1', $text);
        $text = (string) preg_replace('/^[*#:;]+/m', ' ', $text);
        $text = (string) preg_replace('/^\s*-{4,}.*$/m', ' ', $text);
        $text = (string) preg_replace('/__[A-Z_]+__/', ' ', $text);

        // Bold and italics.
        $text = (string) preg_replace("/'{2,}/", '', $text);

        // Leftover HTML tags and entities.
        $text = (string) preg_replace('/<\/?[A-Za-z][^>]*>/', ' ', $text);
        $text = (string) preg_replace('/&[a-zA-Z]{2,8};|&#\d{1,6};/', ' ', $text);

        // Collect whitespace.
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * @param array<string, list<string>> $keep
     */
    private static function stripTemplates(string $text, bool $stripTemplates, array $keep): string
    {
        return self::repeat($text, static function (string $round) use ($stripTemplates, $keep): string {
            return (string) preg_replace_callback(
                '/\{\{([^{}]*)\}\}/s',
                static function (array $matches) use ($stripTemplates, $keep): string {
                    $kept = self::keptParameterValues($matches[1], $keep);
                    if ($kept !== null) {
                        return ' ' . $kept . ' ';
                    }
                    return $stripTemplates ? ' ' : $matches[0];
                },
                $round
            );
        });
    }

    /**
     * Applies a step until it stops changing the text. That is what clears
     * nested constructs from the inside out.
     *
     * @param callable(string): string $step
     */
    private static function repeat(string $text, callable $step): string
    {
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $next = $step($text);
            if ($next === $text) {
                break;
            }
            $text = $next;
        }
        return $text;
    }

    /**
     * Normalises the configured template names so that "Address",
     * "Vorlage:Address" and "address" all mean the same thing.
     *
     * @param array<string, list<string>> $keepTemplateParams
     * @return array<string, list<string>>
     */
    private static function normaliseKeepList(array $keepTemplateParams): array
    {
        $normalised = [];
        foreach ($keepTemplateParams as $name => $params) {
            $normalised[self::normaliseTemplateName((string) $name)] = array_values(
                array_map('trim', $params)
            );
        }
        return $normalised;
    }

    private static function normaliseTemplateName(string $name): string
    {
        $name = trim(str_replace('_', ' ', ltrim(trim($name), ':')));
        $name = (string) preg_replace('/^(?:Vorlage|Template)\s*:\s*/iu', '', $name);

        return mb_strtolower($name);
    }

    /**
     * The values worth keeping from one template call, or null if this call is
     * not on the keep list.
     *
     * Only the values are returned, never the parameter names: indexing
     * the parameter name would be exactly the noise this class exists to remove.
     *
     * @param string $inner Everything between the braces
     * @param array<string, list<string>> $keep
     */
    private static function keptParameterValues(string $inner, array $keep): ?string
    {
        if ($keep === [] || str_starts_with(ltrim($inner), '#')) {
            // Parser functions ({{#set:…}}, {{#if:…}}) are never templates.
            return null;
        }

        $parts = explode('|', $inner);
        $name = self::normaliseTemplateName((string) array_shift($parts));
        if (!isset($keep[$name])) {
            return null;
        }
        $wanted = $keep[$name];

        $values = [];
        foreach ($parts as $part) {
            $position = strpos($part, '=');
            if ($position === false) {
                // Positional parameter: no name to match against the keep list.
                continue;
            }
            $key = trim(substr($part, 0, $position));
            $value = trim(substr($part, $position + 1));
            if ($value !== '' && in_array($key, $wanted, true)) {
                $values[] = $value;
            }
        }

        return implode(' ', $values);
    }

    private static function stripLinks(string $text, bool $stripFiles): string
    {
        $media = '/^\s*:?\s*(?:' . self::MEDIA_NAMESPACES . ')\s*:/iu';
        $category = '/^\s*:?\s*(?:' . self::CATEGORY_NAMESPACES . ')\s*:/iu';

        return self::repeat($text, static function (string $round) use ($media, $category, $stripFiles): string {
            // The character class excludes brackets, so this only ever matches
            // the innermost link. Repeated rounds work outwards from there.
            return (string) preg_replace_callback(
                '/\[\[([^\[\]]*)\]\]/u',
                static function (array $matches) use ($media, $category, $stripFiles): string {
                    return self::renderLink($matches[1], $media, $category, $stripFiles);
                },
                $round
            );
        });
    }

    private static function renderLink(
        string $inner,
        string $media,
        string $category,
        bool $stripFiles
    ): string {

        // Categories render as a box at the bottom of the page, never as
        // running text.
        if (preg_match($category, $inner) === 1) {
            return ' ';
        }

        if (preg_match($media, $inner) === 1) {
            if ($stripFiles) {
                return ' ';
            }
            // Keep everything, file name included, but drop the pipes so
            // display options do not glue onto words.
            return ' ' . str_replace('|', ' ', $inner) . ' ';
        }

        // [[Target|Label]] -> Label
        if (str_contains($inner, '|')) {
            $parts = explode('|', $inner);
            return ' ' . trim((string) end($parts)) . ' ';
        }
        // [[Property::Value]] -> Value
        if (str_contains($inner, '::')) {
            $parts = explode('::', $inner);
            return ' ' . trim((string) end($parts)) . ' ';
        }
        return ' ' . trim($inner) . ' ';
    }
}
