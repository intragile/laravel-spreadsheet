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
}
