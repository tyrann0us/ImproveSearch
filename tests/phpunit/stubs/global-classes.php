<?php

/**
 * Stub definitions for MediaWiki global (non-namespaced) classes.
 * Used by the standalone PHPUnit test bootstrap.
 */

declare(strict_types=1);

if (!class_exists(Skin::class)) {
    class Skin
    {
        public function __construct(private string $skinName = 'vector')
        {
        }

        public function getSkinName(): string
        {
            return $this->skinName;
        }
    }
}

if (!class_exists(SearchSuggestionSet::class)) {
    class SearchSuggestionSet
    {
        /** @param list<mixed> $titles */
        private function __construct(public readonly array $titles)
        {
        }

        /** @param list<mixed> $titles */
        public static function fromTitles(array $titles): self
        {
            return new self($titles);
        }

        public static function emptySuggestionSet(): self
        {
            return new self([]);
        }
    }
}

if (!class_exists(SearchResult::class)) {
    class SearchResult
    {
        public function __construct(private ?\MediaWiki\Title\Title $title = null)
        {
        }

        public function getTitle(): ?\MediaWiki\Title\Title
        {
            return $this->title;
        }
    }
}

if (!class_exists(SqlSearchResultSet::class)) {
    class SqlSearchResultSet
    {
        /** @param list<string> $terms */
        public function __construct(
            public readonly mixed $resultSet,
            public readonly array $terms,
            public readonly int $total = 0
        ) {
        }
    }
}

if (!class_exists(SearchMySQL::class)) {
    /**
     * Enough of SearchEngine/SearchMySQL for the subclass to be analysable
     * and instantiable outside MediaWiki. The real class lives in
     * includes/search/SearchMySQL.php.
     */
    class SearchMySQL
    {
        /** @var int[]|null */
        protected $namespaces = [NS_MAIN];
        protected int $limit = 10;
        protected int $offset = 0;
        protected \Wikimedia\Rdbms\IConnectionProvider $dbProvider;

        public function __construct(\Wikimedia\Rdbms\IConnectionProvider $dbProvider)
        {
            $this->dbProvider = $dbProvider;
        }

        /** @param string $search */
        protected function completionSearchBackend($search): SearchSuggestionSet
        {
            return SearchSuggestionSet::emptySuggestionSet();
        }

        /** @param string $term */
        protected function doSearchTitleInDB($term): ?SqlSearchResultSet
        {
            return null;
        }

        /**
         * @param string $term
         * @return iterable<SearchResult>|false
         */
        protected function doSearchTextInDB($term)
        {
            return false;
        }

        public function setLimitOffset(int $limit, int $offset = 0): void
        {
            $this->limit = $limit;
            $this->offset = $offset;
        }

        protected function queryFeatures(\Wikimedia\Rdbms\SelectQueryBuilder $queryBuilder): void
        {
        }
    }
}
