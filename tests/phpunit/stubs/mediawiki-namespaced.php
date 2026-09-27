<?php

/**
 * Stub definitions for MediaWiki namespaced classes.
 * Each namespace block is wrapped in class_exists guards.
 *
 * Used by the standalone PHPUnit test bootstrap.
 */

declare(strict_types=1);

// ─── MediaWiki\Config ─────────────────────────────────────────────

namespace MediaWiki\Config {
    if (!interface_exists(Config::class)) {
        interface Config
        {
            public function get(string $name): mixed;
        }
    }

    if (!class_exists(HashConfig::class)) {
        class HashConfig implements Config
        {
            /** @param array<string, mixed> $settings */
            public function __construct(private array $settings = [])
            {
            }

            public function get(string $name): mixed
            {
                return $this->settings[$name] ?? null;
            }

            public function set(string $name, mixed $value): void
            {
                $this->settings[$name] = $value;
            }
        }
    }
}

// ─── MediaWiki ────────────────────────────────────────────────────

namespace MediaWiki {
    if (!class_exists(MainConfigNames::class)) {
        class MainConfigNames
        {
            public const ContentHandlers = 'ContentHandlers';
            public const DBtype = 'DBtype';
            public const DefaultUserOptions = 'DefaultUserOptions';
            public const SearchType = 'SearchType';
            public const ThumbnailNamespaces = 'ThumbnailNamespaces';
        }
    }

    if (!class_exists(MediaWikiServices::class)) {
        class MediaWikiServices
        {
            private static ?self $instance = null;

            private function __construct(private \MediaWiki\Config\Config $mainConfig)
            {
            }

            /** Tests prime the container with the config the code under test reads. */
            public static function setInstanceForTesting(?\MediaWiki\Config\Config $config): void
            {
                self::$instance = $config === null ? null : new self($config);
            }

            public static function getInstance(): self
            {
                self::$instance ??= new self(new \MediaWiki\Config\HashConfig());
                return self::$instance;
            }

            public function getMainConfig(): \MediaWiki\Config\Config
            {
                return $this->mainConfig;
            }
        }
    }
}

// ─── MediaWiki\Settings ───────────────────────────────────────────

namespace MediaWiki\Settings {
    if (!class_exists(SettingsBuilder::class)) {
        class SettingsBuilder
        {
            /** @var array<string, mixed> */
            public array $overrides = [];

            public function __construct(private \MediaWiki\Config\Config $config)
            {
            }

            public function getConfig(): \MediaWiki\Config\Config
            {
                return $this->config;
            }

            public function overrideConfigValue(string $key, mixed $value): self
            {
                $this->overrides[$key] = $value;
                if ($this->config instanceof \MediaWiki\Config\HashConfig) {
                    $this->config->set($key, $value);
                }
                return $this;
            }
        }
    }
}

// ─── MediaWiki\Content ────────────────────────────────────────────

namespace MediaWiki\Content {
    if (!class_exists(WikitextContent::class)) {
        class WikitextContent
        {
            public function __construct(
                private string $text = '',
                private ?string $redirectTarget = null
            ) {
            }

            public function getText(): string
            {
                return $this->text;
            }

            public function getRedirectTarget(): ?string
            {
                return $this->redirectTarget;
            }
        }
    }

    if (!class_exists(WikitextContentHandler::class)) {
        class WikitextContentHandler
        {
            protected function getContentClass(): string
            {
                return WikitextContent::class;
            }
        }
    }
}

// ─── MediaWiki\Output ─────────────────────────────────────────────

namespace MediaWiki\Output {
    if (!class_exists(OutputPage::class)) {
        class OutputPage
        {
            /** @var list<string> */
            public array $modules = [];

            /** @param string|list<string> $modules */
            public function addModules($modules): void
            {
                foreach ((array) $modules as $module) {
                    $this->modules[] = $module;
                }
            }
        }
    }
}

namespace MediaWiki\Output\Hook {
    if (!interface_exists(BeforePageDisplayHook::class)) {
        interface BeforePageDisplayHook
        {
            /**
             * @param \MediaWiki\Output\OutputPage $out
             * @param \Skin $skin
             */
            public function onBeforePageDisplay($out, $skin);
        }
    }
}

// ─── MediaWiki\Registration ───────────────────────────────────────

namespace MediaWiki\Registration {
    if (!class_exists(ExtensionRegistry::class)) {
        class ExtensionRegistry
        {
            private static ?self $instance = null;

            /** @var list<string> */
            private array $loaded = [];

            /** @param list<string> $loaded */
            public static function setInstanceForTesting(array $loaded): void
            {
                self::$instance = new self();
                self::$instance->loaded = $loaded;
            }

            public static function getInstance(): self
            {
                self::$instance ??= new self();
                return self::$instance;
            }

            public function isLoaded(string $name): bool
            {
                return in_array($name, $this->loaded, true);
            }
        }
    }
}

// ─── MediaWiki\Title ──────────────────────────────────────────────

namespace MediaWiki\Title {
    if (!class_exists(Title::class)) {
        class Title
        {
            private function __construct(
                public readonly int $namespace,
                public readonly string $dbKey,
                private int $articleId = 0
            ) {
            }

            public static function makeTitle(int $namespace, string $title): self
            {
                return new self($namespace, $title);
            }

            /** Tests need titles that differ by page id, which makeTitle cannot express. */
            public function withArticleId(int $articleId): self
            {
                $this->articleId = $articleId;
                return $this;
            }

            public function getArticleID(): int
            {
                return $this->articleId;
            }
        }
    }
}

// ─── Wikimedia\Rdbms ──────────────────────────────────────────────

namespace Wikimedia\Rdbms {
    if (!interface_exists(IReadableDatabase::class)) {
        interface IReadableDatabase
        {
            public function newSelectQueryBuilder(): SelectQueryBuilder;

            public function addQuotes(string $value): string;

            /** @param mixed $value */
            public function expr(string $field, string $operator, $value): mixed;
        }
    }

    if (!interface_exists(IConnectionProvider::class)) {
        interface IConnectionProvider
        {
            public function getReplicaDatabase(): IReadableDatabase;
        }
    }

    if (!class_exists(SelectQueryBuilder::class)) {
        /**
         * Records what the query builder was asked to build and returns the
         * rows the test seeded. Enough for assertions about conditions and
         * ordering without a database.
         */
        class SelectQueryBuilder
        {
            /** @var list<mixed> */
            public array $fields = [];
            public string $table = '';
            /** @var list<mixed> */
            public array $conditions = [];
            /** @var list<string> */
            public array $order = [];
            public ?int $limitValue = null;
            public ?int $offsetValue = null;

            /** @var list<\stdClass> */
            public array $rows = [];
            public mixed $field = 0;

            /** @param string|list<string> $fields */
            public function select($fields): self
            {
                $this->fields = (array) $fields;
                return $this;
            }

            public function from(string $table): self
            {
                $this->table = $table;
                return $this;
            }

            /** @param string|array<string, mixed> $conditions */
            public function where($conditions): self
            {
                $this->conditions[] = $conditions;
                return $this;
            }

            /** @param string|array<string, mixed>|mixed $conditions */
            public function andWhere($conditions): self
            {
                $this->conditions[] = $conditions;
                return $this;
            }

            /** @param string|list<string> $fields */
            public function orderBy($fields): self
            {
                $this->order = (array) $fields;
                return $this;
            }

            public function limit(int $limit): self
            {
                $this->limitValue = $limit;
                return $this;
            }

            public function offset(int $offset): self
            {
                $this->offsetValue = $offset;
                return $this;
            }

            public function caller(string $caller): self
            {
                return $this;
            }

            /** @return iterable<\stdClass> */
            public function fetchResultSet(): iterable
            {
                return $this->rows;
            }

            public function fetchField(): mixed
            {
                return $this->field;
            }
        }
    }

    if (!class_exists(FakeResultWrapper::class)) {
        class FakeResultWrapper
        {
            /** @param list<\stdClass> $rows */
            public function __construct(public readonly array $rows)
            {
            }
        }
    }
}
