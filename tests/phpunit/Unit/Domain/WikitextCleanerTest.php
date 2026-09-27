<?php

declare(strict_types=1);

namespace MediaWiki\Extension\ImproveSearch\Tests\Unit\Domain;

use MediaWiki\Extension\ImproveSearch\Domain\WikitextCleaner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WikitextCleaner::class)]
class WikitextCleanerTest extends TestCase
{
    /**
     * Runs of spaces are an artefact of replacing markup with a single
     * space; the index does not care and neither do these assertions.
     */
    private static function normalise(string $text): string
    {
        return (string) preg_replace('/[ \t]+/', ' ', $text);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function assertCleans(string $wikitext, string $expected, array $options = []): void
    {
        self::assertSame(
            self::normalise($expected),
            self::normalise(WikitextCleaner::clean($wikitext, $options))
        );
    }

    /**
     * Localised namespace names are part of the contract, so the cases use
     * the German aliases alongside the canonical English ones.
     *
     * @return array<string, array{string, string}>
     */
    public static function markupProvider(): array
    {
        return [
            'file embed disappears' => [
                "[[Datei:Foo Bar.jpg|mini|A caption.]]\nVisible text.",
                'Visible text.',
            ],
            'File and Bild alike' => [
                '[[File:A.png|thumb|x]][[Bild:B.jpg|thumb]]Text.',
                'Text.',
            ],
            'caption containing a link' => [
                '[[Datei:X.jpg|mini|A view of [[Bridge Street]].]]Rest.',
                'Rest.',
            ],
            'caption containing an external link' => [
                '[[File:X.jpg|thumb|View from the bridge, 1805. '
                    . "[https://example.org/70301742 Photo Archive]]]\nRest.",
                'Rest.',
            ],
            'caption containing a bare address' => [
                '[[File:X.jpg|thumb|Source https://example.org/a on this]]Rest.',
                'Rest.',
            ],
            'template with parameters disappears' => [
                '{{Address|street=Main Street|number=12}}The house.',
                'The house.',
            ],
            'nested template' => [
                '{{a|{{b|c}}|d}}Text.',
                'Text.',
            ],
            'category disappears' => [
                "Text.\n[[Kategorie:Local history]]",
                'Text.',
            ],
            'link keeps its visible part' => [
                'He lived in [[Old Town|the old town]] near the [[Market Square]].',
                'He lived in the old town near the Market Square .',
            ],
            'semantic annotation keeps its value' => [
                '[[Street::Bergstraße]]',
                'Bergstraße',
            ],
            'reference disappears' => [
                'Sentence.<ref>Source {{Cite|pages=203}}</ref> More.',
                'Sentence. More.',
            ],
            'empty ref tag disappears' => [
                'Sentence.<ref name="x" /> More.',
                'Sentence. More.',
            ],
            'heading becomes text' => [
                "==History==\nSentence.",
                "History\nSentence.",
            ],
            'bold markup disappears' => [
                "The '''house''' is old.",
                'The house is old.',
            ],
            'list marker disappears' => [
                '* A point',
                'A point',
            ],
            'external link keeps its label' => [
                'See [https://example.org/a the example] there.',
                'See the example there.',
            ],
            'bare address disappears' => [
                'See https://example.org/a there.',
                'See there.',
            ],
            'gallery disappears whole' => [
                "<gallery>\nFile:A.jpg|x\n</gallery>Text.",
                'Text.',
            ],
            'comment disappears' => [
                'Before<!-- hidden -->after.',
                'Before after.',
            ],
        ];
    }

    #[DataProvider('markupProvider')]
    public function testMarkupIsRemoved(string $wikitext, string $expected): void
    {
        self::assertCleans($wikitext, $expected);
    }

    /**
     * Selected template parameters can be kept, values only: indexing
     * "sortkey" would be exactly the noise this class exists to remove.
     *
     * @return array<string, array{string, string, array<string, list<string>>}>
     */
    public static function keepTemplateParamsProvider(): array
    {
        $address = '{{Address|street=Wharf Road|number=7|sortkey=7}}The warehouse.';
        $keepAddress = ['Address' => ['street', 'number']];

        return [
            'values are kept, parameter names are not' => [
                $address,
                'Wharf Road 7 The warehouse.',
                $keepAddress,
            ],
            'other templates are still dropped' => [
                '{{Cleanup}}' . $address,
                'Wharf Road 7 The warehouse.',
                $keepAddress,
            ],
            'prefixed and differently cased names match' => [
                '{{vorlage:address|street=Wharf Road|number=7}}x',
                'Wharf Road 7 x',
                $keepAddress,
            ],
            'underscores in the name match' => [
                '{{Müller_und_Söhne|x=Wharf Road}}y',
                'Wharf Road y',
                ['Müller und Söhne' => ['x']],
            ],
            'parser functions are never kept' => [
                '{{#set:Street=Wharf Road}}x',
                'x',
                ['#set' => ['Street']],
            ],
            'an empty keep list drops everything' => [
                $address,
                'The warehouse.',
                [],
            ],
            'positional parameters have no name to match' => [
                '{{Address|Wharf Road|number=7}}x',
                '7 x',
                $keepAddress,
            ],
            'empty values contribute nothing' => [
                '{{Address|street=|number=7}}x',
                '7 x',
                $keepAddress,
            ],
        ];
    }

    /**
     * @param array<string, list<string>> $keep
     */
    #[DataProvider('keepTemplateParamsProvider')]
    public function testKeepTemplateParams(string $wikitext, string $expected, array $keep): void
    {
        self::assertCleans($wikitext, $expected, ['keepTemplateParams' => $keep]);
    }

    public function testUnselectedParameterValuesAreNotIndexed(): void
    {
        $clean = WikitextCleaner::clean(
            '{{Address|street=Wharf Road|number=7|sortkey=7}}The warehouse.',
            ['keepTemplateParams' => ['Address' => ['street', 'number']]]
        );

        self::assertStringNotContainsString('sortkey', $clean);
        // "7" comes from `number`, once. `sortkey` must not add a second.
        self::assertSame(1, substr_count($clean, '7'));
    }

    public function testFilesSwitchKeepsTheFileName(): void
    {
        self::assertStringContainsString(
            'Foo Bar.jpg',
            WikitextCleaner::clean('[[File:Foo Bar.jpg|thumb|A caption.]]Text.', ['files' => false])
        );
    }

    public function testTemplatesSwitchKeepsTheParameters(): void
    {
        self::assertStringContainsString(
            'Main Street',
            WikitextCleaner::clean('{{Address|street=Main Street}}x', ['templates' => false])
        );
    }

    /**
     * The keep list and the templates switch are independent. With templates
     * kept, a call that is not on the keep list has to survive untouched.
     */
    public function testKeepListLeavesOtherTemplatesIntactWhenTemplatesAreKept(): void
    {
        $clean = WikitextCleaner::clean(
            '{{Cleanup|reason=x}}{{Address|street=Wharf Road|sortkey=7}}y',
            ['templates' => false, 'keepTemplateParams' => ['Address' => ['street']]]
        );

        self::assertStringContainsString('{{Cleanup|reason=x}}', $clean);
        self::assertStringContainsString('Wharf Road', $clean);
        self::assertStringNotContainsString('sortkey', $clean);
    }

    /**
     * A realistic page: nothing that should go may survive, nothing that
     * should stay may be lost.
     */
    public function testRealisticPage(): void
    {
        $page = <<<'WIKI'
        {{Cleanup}}{{Infobox road}}[[File:10855-Stone-Bridge-1909-Böhme & Son Publishers.jpg|thumb|The school on Bridge Street.]]
        '''Bridge Street''' runs through [[Old Town]] in the [[Riverside]] district.
        {{Buildings in this street}}
        [[Category:Local history]]
        WIKI;

        $clean = WikitextCleaner::clean($page);

        foreach (['.jpg', 'thumb', 'Publishers', '{{', '}}', '[[', ']]', 'Category', "'''"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $clean);
        }
        foreach (['Bridge Street', 'Old Town', 'Riverside'] as $needed) {
            self::assertStringContainsString($needed, $clean);
        }
    }
}
