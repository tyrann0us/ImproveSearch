<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SearchMySQL;
use SearchSuggestionSet;
use SqlSearchResultSet;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * MySQL search engine that matches the term anywhere in the page title.
 *
 * Two places change: the suggestion list under the search box
 * (completionSearchBackend, used by OpenSearch and the skins' typeahead),
 * which core answers with a prefix search, and the title hits on
 * Special:Search (doSearchTitleInDB), which core answers with a word-wise
 * fulltext match on searchindex.si_title.
 *
 * Full-text search over page content is left to MySQL fulltext, unchanged.
 *
 * Search engines are built by SearchEngineFactory from $wgSearchType, which
 * passes only a database connection, so the configuration is read from the
 * service container here rather than injected.
 */
class SubstringTitleSearchEngine extends SearchMySQL
{
    /**
     * Page ids already returned as title matches in this request.
     *
     * Special:Search asks for title matches first and text matches second, and
     * prints both blocks. Without this a page that matches in its name and in
     * its text is listed twice, one entry above the other.
     *
     * @var list<int>
     */
    private array $titleMatchIds = [];

    /**
     * Suggestion list under the search box.
     *
     * @param string $search
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- SearchEngine signature
    protected function completionSearchBackend($search): SearchSuggestionSet
    {
        $search = trim((string) $search);

        // Special pages are not rows in the page table.
        if ($search === '' || in_array(NS_SPECIAL, (array) $this->namespaces, true)) {
            return parent::completionSearchBackend($search);
        }

        $titles = [];
        $seen = [];
        foreach ($this->matchingTitles($search, $this->limit, $this->offset) as $row) {
            $titles[] = Title::makeTitle((int) $row->page_namespace, (string) $row->page_title);
            $seen[(int) $row->page_id] = true;
        }

        foreach ($this->contentMatches($search, $this->limit - count($titles), $seen) as $title) {
            $titles[] = $title;
        }

        return SearchSuggestionSet::fromTitles($titles);
    }

    /**
     * Pages that carry the term in their text but not in their name, used to
     * top up a suggestion list the title search could not fill.
     *
     * Without this the suggestion list can only ever offer what is spelled out
     * in a page name. A house whose address lives in a template parameter, or
     * any page whose subject is named only in its text, stays invisible there
     * however well the full-text index knows it.
     *
     * Strictly additive: title matches keep their places and their order, and
     * nothing runs at all while the title search still fills the list. The
     * source and the order are the same as for the text hits on the results
     * page, so the two lists cannot drift apart.
     *
     * @param int $room How many suggestions are still free
     * @param array<int, true> $seen Page ids already suggested
     * @return list<Title>
     */
    private function contentMatches(string $search, int $room, array $seen): array
    {
        if ($room < 1 || $this->offset > 0) {
            // Paging mixed sources would shuffle the list between pages.
            return [];
        }
        if (!self::config()->get('ImproveSearchSuggestContentMatches')) {
            return [];
        }

        $originalLimit = $this->limit;
        try {
            // Overfetch: some hits are already in the list.
            $this->setLimitOffset($room + count($seen), 0);
            $results = $this->doSearchTextInDB($search);
        } finally {
            $this->setLimitOffset($originalLimit, 0);
        }

        if (!$results) {
            return [];
        }

        $titles = [];
        foreach ($results as $result) {
            $title = $result->getTitle();
            if ($title === null || isset($seen[$title->getArticleID()])) {
                continue;
            }
            $titles[] = $title;
            if (count($titles) >= $room) {
                break;
            }
        }

        return $titles;
    }

    /**
     * Title hits on Special:Search.
     *
     * @param string $term
     */
    // phpcs:ignore Syde.Functions.ArgumentTypeDeclaration.NoArgumentType -- SearchEngine signature
    protected function doSearchTitleInDB($term): ?SqlSearchResultSet
    {
        $term = trim((string) $term);
        if ($term === '') {
            return null;
        }

        return new SqlSearchResultSet(
            new FakeResultWrapper($this->matchingTitles($term, $this->limit, $this->offset)),
            [preg_quote($term, '/')],
            $this->countMatchingTitles($term)
        );
    }

    /**
     * Pages whose name contains the string somewhere. Matches at the start of
     * the name come first, then alphabetically.
     *
     * This is a full table scan: LIKE '%…%' cannot use an index, and neither
     * can the case folding below. On a wiki of a few thousand pages that costs
     * a millisecond or two and beats maintaining a second, folded copy of every
     * title. Wikis in the hundreds of thousands of pages want CirrusSearch
     * instead of this extension.
     *
     * @return list<\stdClass>
     */
    private function matchingTitles(string $search, int $limit, int $offset): array
    {
        $rows = iterator_to_array(
            $this->titleQuery($search)
                ->select(['page_id', 'page_namespace', 'page_title'])
                ->orderBy($this->titleOrder($search))
                ->limit($limit)
                ->offset($offset)
                ->caller(__METHOD__)
                ->fetchResultSet(),
            false
        );

        foreach ($rows as $row) {
            $this->titleMatchIds[] = (int) $row->page_id;
        }

        return $rows;
    }

    /**
     * Core adds this to both the text query and its count query, which is
     * exactly where the pages already listed as title matches have to drop
     * out.
     *
     */
    protected function queryFeatures(SelectQueryBuilder $queryBuilder): void
    {
        parent::queryFeatures($queryBuilder);

        if ($this->titleMatchIds === []) {
            return;
        }

        $queryBuilder->andWhere(
            $this->dbProvider->getReplicaDatabase()
                ->expr('page_id', '!=', array_values(array_unique($this->titleMatchIds)))
        );
    }

    private function countMatchingTitles(string $search): int
    {
        return (int) $this->titleQuery($search)
            ->select('COUNT(*)')
            ->caller(__METHOD__)
            ->fetchField();
    }

    /**
     * The page table, narrowed to the rows that count as a title match. The
     * listing and the count have to agree, so both start here.
     */
    private function titleQuery(string $search): SelectQueryBuilder
    {
        return $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
            ->from('page')
            ->where($this->substringCondition($search))
            ->andWhere($this->namespaceCondition())
            ->andWhere($this->redirectCondition());
    }

    private function substringCondition(string $search): string
    {
        $dbr = $this->dbProvider->getReplicaDatabase();

        // A space is stored as an underscore in page_title. The underscore
        // stays unescaped: as a LIKE wildcard it matches exactly the character
        // we are looking for.
        $needle = str_replace(
            ['\\', '%'],
            ['\\\\', '\\%'],
            str_replace(' ', '_', $search)
        );
        $pattern = $dbr->addQuotes('%' . $needle . '%');

        return self::asText('page_title') . ' LIKE ' . self::asText($pattern);
    }

    /**
     * Matches at the start of the name first, then further in, then
     * alphabetically.
     *
     * The first term needs explaining. LIKE folds umlauts under
     * utf8mb4_unicode_ci, so "muller" matches "Müllerweg", but LOCATE does
     * not fold, and reports position 0, "not found", for that row. Sorted
     * plainly by position, 0 comes first and the loosest matches end up at the
     * very top. So rows LOCATE cannot place go last instead.
     *
     * @return list<string>
     */
    private function titleOrder(string $search): array
    {
        $needle = $this->dbProvider->getReplicaDatabase()
            ->addQuotes(str_replace(' ', '_', $search));
        $position = 'LOCATE(' . self::asText($needle) . ', ' . self::asText('page_title') . ')';

        return ["($position = 0)", $position, 'page_title'];
    }

    /**
     * page_title is varbinary and so is the quoted search term, so a plain
     * LIKE would be case sensitive. Reading both as utf8mb4 under a
     * case-insensitive collation fixes that, and folds umlauts and ß along the
     * way: "Zurich" finds "Zürich", "Muller" finds "Müller".
     */
    private static function asText(string $expression): string
    {
        return "CONVERT($expression USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    }

    /**
     * A redirect is not a result, it is a signpost to one. Listing both sends
     * the reader to the same article twice under two names.
     *
     * @return array<string, int>
     */
    private function redirectCondition(): array
    {
        if (!self::config()->get('ImproveSearchIgnoreRedirects')) {
            return [];
        }

        return ['page_is_redirect' => 0];
    }

    /**
     * @return array<string, list<int>>
     */
    private function namespaceCondition(): array
    {
        $namespaces = $this->namespaces;
        if (!is_array($namespaces) || $namespaces === []) {
            $namespaces = [NS_MAIN];
        }

        return ['page_namespace' => array_values($namespaces)];
    }

    private static function config(): Config
    {
        return MediaWikiServices::getInstance()->getMainConfig();
    }
}
