<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Unit\Infrastructure\MediaWiki;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\ImproveSearch\Infrastructure\MediaWiki\SubstringTitleSearchEngine;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SearchResult;
use SqlSearchResultSet;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Records every query the engine builds and hands back seeded rows.
 */
final class RecordingDatabase implements IReadableDatabase
{
    /** @var list<SelectQueryBuilder> */
    public array $builders = [];

    /** @var list<\stdClass> */
    public array $rows = [];

    public int $count = 0;

    /** @var list<array{string, string, mixed}> */
    public array $expressions = [];

    public function newSelectQueryBuilder(): SelectQueryBuilder
    {
        $builder = new SelectQueryBuilder();
        $builder->rows = $this->rows;
        $builder->field = $this->count;
        $this->builders[] = $builder;

        return $builder;
    }

    public function addQuotes(string $value): string
    {
        return "'" . $value . "'";
    }

    /** @param mixed $value */
    public function expr(string $field, string $operator, $value): mixed
    {
        $this->expressions[] = [$field, $operator, $value];

        return 'EXPR';
    }
}

final class FixedConnectionProvider implements IConnectionProvider
{
    public function __construct(private IReadableDatabase $database)
    {
    }

    public function getReplicaDatabase(): IReadableDatabase
    {
        return $this->database;
    }
}

/**
 * Opens the protected search-engine surface and stands in for the full-text
 * query, which core answers from searchindex.
 */
final class TestableSearchEngine extends SubstringTitleSearchEngine
{
    /** @var list<SearchResult>|false */
    public array|false $textResults = false;

    public int $textSearches = 0;

    public ?int $textSearchLimit = null;

    /** @param int[]|null $namespaces */
    public function setNamespaces(?array $namespaces): void
    {
        $this->namespaces = $namespaces;
    }

    public function setLimits(int $limit, int $offset): void
    {
        $this->limit = $limit;
        $this->offset = $offset;
    }

    public function completionSearch(string $search): \SearchSuggestionSet
    {
        return $this->completionSearchBackend($search);
    }

    public function titleSearch(string $term): ?SqlSearchResultSet
    {
        return $this->doSearchTitleInDB($term);
    }

    public function applyQueryFeatures(SelectQueryBuilder $queryBuilder): void
    {
        $this->queryFeatures($queryBuilder);
    }

    /** @param string $term */
    protected function doSearchTextInDB($term)
    {
        $this->textSearches++;
        $this->textSearchLimit = $this->limit;

        return $this->textResults;
    }
}

#[CoversClass(SubstringTitleSearchEngine::class)]
class SubstringTitleSearchEngineTest extends TestCase
{
    private RecordingDatabase $database;

    protected function tearDown(): void
    {
        MediaWikiServices::setInstanceForTesting(null);
        parent::tearDown();
    }

    /**
     * @param list<\stdClass> $rows
     * @param array<string, mixed> $config
     */
    private function engine(array $rows = [], int $count = 0, array $config = []): TestableSearchEngine
    {
        MediaWikiServices::setInstanceForTesting(new HashConfig($config + [
            'ImproveSearchSuggestContentMatches' => true,
            'ImproveSearchIgnoreRedirects' => true,
        ]));

        $this->database = new RecordingDatabase();
        $this->database->rows = $rows;
        $this->database->count = $count;

        return new TestableSearchEngine(new FixedConnectionProvider($this->database));
    }

    private static function row(int $pageId, string $title, int $namespace = NS_MAIN): \stdClass
    {
        return (object) [
            'page_id' => $pageId,
            'page_namespace' => $namespace,
            'page_title' => $title,
        ];
    }

    private static function title(int $articleId): Title
    {
        return Title::makeTitle(NS_MAIN, 'Text hit ' . $articleId)->withArticleId($articleId);
    }

    /**
     * @return list<string>
     */
    private static function dbKeys(\SearchSuggestionSet $suggestions): array
    {
        return array_map(static fn (Title $title): string => $title->dbKey, $suggestions->titles);
    }

    public function testEmptyTermIsLeftToCore(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge')]);

        self::assertSame([], self::dbKeys($engine->completionSearch('   ')));
        self::assertSame([], $this->database->builders);
    }

    public function testSpecialPagesAreLeftToCore(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge')]);
        $engine->setNamespaces([NS_SPECIAL]);

        self::assertSame([], self::dbKeys($engine->completionSearch('bridge')));
        self::assertSame([], $this->database->builders);
    }

    public function testSuggestsTitleMatches(): void
    {
        $engine = $this->engine([self::row(1, 'Bridge_Street'), self::row(2, 'Stone_Bridge')]);
        $engine->setLimits(2, 0);

        self::assertSame(
            ['Bridge_Street', 'Stone_Bridge'],
            self::dbKeys($engine->completionSearch('bridge'))
        );
        // Full list, so the text index is never consulted.
        self::assertSame(0, $engine->textSearches);
    }

    public function testTopsUpWithContentMatchesOnceTitlesRunOut(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge')]);
        $engine->setLimits(3, 0);
        $engine->textResults = [
            // Already suggested as a title match, so it must not appear twice.
            new SearchResult(self::title(1)),
            // A hit without a title, which the full-text query can produce.
            new SearchResult(null),
            new SearchResult(self::title(7)),
            new SearchResult(self::title(8)),
            // One more than there is room for.
            new SearchResult(self::title(9)),
        ];

        self::assertSame(
            ['Stone_Bridge', 'Text hit 7', 'Text hit 8'],
            self::dbKeys($engine->completionSearch('bridge'))
        );
        self::assertSame(1, $engine->textSearches);
        // Overfetch: two free slots plus the one page already suggested.
        self::assertSame(3, $engine->textSearchLimit);
    }

    public function testNoContentQueryWhileTitlesFillTheList(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge')]);
        $engine->setLimits(1, 0);

        self::assertSame(['Stone_Bridge'], self::dbKeys($engine->completionSearch('bridge')));
        self::assertSame(0, $engine->textSearches);
    }

    public function testNoContentQueryOnLaterPages(): void
    {
        $engine = $this->engine();
        $engine->setLimits(5, 5);

        self::assertSame([], self::dbKeys($engine->completionSearch('bridge')));
        self::assertSame(0, $engine->textSearches);
    }

    public function testContentMatchesCanBeSwitchedOff(): void
    {
        $engine = $this->engine([], 0, ['ImproveSearchSuggestContentMatches' => false]);
        $engine->setLimits(5, 0);
        $engine->textResults = [new SearchResult(self::title(7))];

        self::assertSame([], self::dbKeys($engine->completionSearch('bridge')));
        self::assertSame(0, $engine->textSearches);
    }

    public function testNoTextHitsLeavesTheListAsItIs(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge')]);
        $engine->setLimits(5, 0);
        $engine->textResults = false;

        self::assertSame(['Stone_Bridge'], self::dbKeys($engine->completionSearch('bridge')));
        self::assertSame(1, $engine->textSearches);
    }

    public function testTitleSearchIgnoresAnEmptyTerm(): void
    {
        self::assertNull($this->engine()->titleSearch('  '));
    }

    public function testTitleSearchReportsTheFullCount(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge')], 7);

        $results = $engine->titleSearch('bridge');

        self::assertInstanceOf(SqlSearchResultSet::class, $results);
        self::assertSame(7, $results->total);
        self::assertSame(['bridge'], $results->terms);
        self::assertEquals([self::row(1, 'Stone_Bridge')], $results->resultSet->rows);
    }

    public function testQueryIsCaseFoldedAndEscaped(): void
    {
        $engine = $this->engine();
        $engine->setLimits(4, 2);

        $engine->titleSearch('main street');

        $listing = $this->database->builders[0];
        self::assertSame('page', $listing->table);
        self::assertSame(4, $listing->limitValue);
        self::assertSame(2, $listing->offsetValue);
        self::assertStringContainsString('utf8mb4_unicode_ci', $listing->conditions[0]);
        // Spaces are underscores in page_title.
        self::assertStringContainsString("'%main_street%'", $listing->conditions[0]);
        self::assertSame(['page_namespace' => [NS_MAIN]], $listing->conditions[1]);
        self::assertSame(['page_is_redirect' => 0], $listing->conditions[2]);
        // Rows LOCATE cannot place go last, then by position, then by name.
        self::assertCount(3, $listing->order);
        self::assertStringEndsWith('= 0)', $listing->order[0]);
        self::assertSame('page_title', $listing->order[2]);
    }

    public function testLikeWildcardsInTheTermAreEscaped(): void
    {
        $engine = $this->engine();

        $engine->titleSearch('50% or\\more');

        self::assertStringContainsString(
            "'%50\\%_or\\\\more%'",
            $this->database->builders[0]->conditions[0]
        );
    }

    public function testRedirectsCanBeIncluded(): void
    {
        $engine = $this->engine([], 0, ['ImproveSearchIgnoreRedirects' => false]);

        $engine->titleSearch('bridge');

        self::assertSame([], $this->database->builders[0]->conditions[2]);
    }

    /**
     * @return array<string, array{int[]|null, int[]}>
     */
    public static function namespaceProvider(): array
    {
        return [
            'none set' => [null, [NS_MAIN]],
            'empty list' => [[], [NS_MAIN]],
            'explicit list' => [[NS_MAIN, NS_FILE], [NS_MAIN, NS_FILE]],
        ];
    }

    /**
     * @param int[]|null $namespaces
     * @param int[] $expected
     */
    #[DataProvider('namespaceProvider')]
    public function testNamespaceCondition(?array $namespaces, array $expected): void
    {
        $engine = $this->engine();
        $engine->setNamespaces($namespaces);

        $engine->titleSearch('bridge');

        self::assertSame(['page_namespace' => $expected], $this->database->builders[0]->conditions[1]);
    }

    /**
     * Core applies queryFeatures() to the text query and to its count query,
     * which is where the pages already listed as title matches drop out.
     */
    public function testTextQueryExcludesPagesAlreadyListedAsTitleMatches(): void
    {
        $engine = $this->engine([self::row(1, 'Stone_Bridge'), self::row(2, 'Bridge_Street')]);
        $engine->titleSearch('bridge');

        $textQuery = new SelectQueryBuilder();
        $engine->applyQueryFeatures($textQuery);

        self::assertSame([['page_id', '!=', [1, 2]]], $this->database->expressions);
        self::assertSame(['EXPR'], $textQuery->conditions);
    }

    public function testTextQueryIsUntouchedWithoutTitleMatches(): void
    {
        $engine = $this->engine();

        $textQuery = new SelectQueryBuilder();
        $engine->applyQueryFeatures($textQuery);

        self::assertSame([], $this->database->expressions);
        self::assertSame([], $textQuery->conditions);
    }
}
