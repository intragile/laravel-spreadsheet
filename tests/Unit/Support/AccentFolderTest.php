<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Support;

use IntrAgile\LaravelSpreadsheet\Support\AccentFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

final class AccentFolderTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function cases(): array
    {
        return [
            'hungarian' => ['árvíztűrő tükörfúrógép ÁÉÍÓÖŐÚÜŰ', 'arvizturo tukorfurogep AEIOOOUUU'],
            'german' => ['Straße Äpfel', 'Strasse Apfel'],
            'nordic' => ['ø å æ Ø Å Æ', 'o a ae O A AE'],
            'polish' => ['ł Ł', 'l L'],
            'french' => ['œ Œ ç ñ', 'oe OE c n'],
            'czech' => ['č š ž ř', 'c s z r'],
            'romanian' => ['ț ș Ț Ș', 't s T S'],
            'plain ascii untouched' => ['Hello World 123', 'Hello World 123'],
        ];
    }

    #[DataProvider('cases')]
    public function test_table_path(string $input, string $expected): void
    {
        $this->assertSame($expected, AccentFolder::foldWithTable($input));
    }

    #[RequiresPhpExtension('intl')]
    #[DataProvider('cases')]
    public function test_intl_path_matches_table_path(string $input, string $expected): void
    {
        $this->assertSame($expected, AccentFolder::fold($input));
        $this->assertSame(AccentFolder::foldWithTable($input), AccentFolder::fold($input));
    }

    #[RequiresPhpExtension('intl')]
    public function test_every_table_character_folds_identically(): void
    {
        for ($code = 0xC0; $code <= 0x17F; $code++) {
            $char = mb_chr($code);

            $this->assertSame(AccentFolder::foldWithTable($char), AccentFolder::fold($char), 'U+'.dechex($code));
        }
    }

    public function test_nfd_input_folds_identically_on_both_paths(): void
    {
        $nfd = "Cafe\u{0301} Ku\u{0308}lo\u{030B}n";

        $this->assertSame('Cafe Kulon', AccentFolder::foldWithTable($nfd));

        if (extension_loaded('intl')) {
            $this->assertSame('Cafe Kulon', AccentFolder::fold($nfd));
        }
    }

    public function test_non_latin_scripts_are_unchanged_by_both_paths(): void
    {
        foreach (['हिन्दी कि', 'สวัสดี', 'مَرْحَبًا', 'שָׁלוֹם', '한국어 한글'] as $text) {
            $this->assertSame($text, AccentFolder::foldWithTable($text));

            if (extension_loaded('intl')) {
                $this->assertSame($text, AccentFolder::fold($text));
            }
        }
    }

    public function test_dotted_capital_i_folds_on_both_paths(): void
    {
        $lowered = mb_strtolower('İstanbul');

        $this->assertSame('istanbul', AccentFolder::foldWithTable($lowered));

        if (extension_loaded('intl')) {
            $this->assertSame('istanbul', AccentFolder::fold($lowered));
        }
    }
}
