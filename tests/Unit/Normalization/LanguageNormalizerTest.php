<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\LanguageNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

final class LanguageNormalizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function resolvable(): array
    {
        return [
            'alpha2' => ['hu', 'hu'],
            'alpha2 upper' => ['HU', 'hu'],
            'alpha3' => ['hun', 'hu'],
            'english name' => ['Hungarian', 'hu'],
            'english name lower' => ['hungarian', 'hu'],
            'german terminologic' => ['deu', 'de'],
            'german bibliographic' => ['ger', 'de'],
            'french bibliographic' => ['fre', 'fr'],
            'spanish' => ['Spanish', 'es'],
        ];
    }

    #[DataProvider('resolvable')]
    public function test_resolves_codes_and_english_names(string $text, string $expected): void
    {
        $result = $this->language($text);

        $this->assertNull($result->error);
        $this->assertSame($expected, $result->value);
    }

    #[RequiresPhpExtension('intl')]
    public function test_resolves_localized_names_for_configured_locales(): void
    {
        $context = new NormalizationContext(locales: ['en', 'hu', 'de']);

        $this->assertSame('hu', $this->language('magyar', $context)->value);
        $this->assertSame('hu', $this->language('Ungarisch', $context)->value);
        $this->assertSame('de', $this->language('német', $context)->value);
        $this->assertSame('spreadsheet::messages.unknown_value', $this->language('magyar')->error);
    }

    public function test_unknown_values_are_errors(): void
    {
        foreach (['xx', 'Klingonish', 'zzz'] as $text) {
            $result = $this->language($text);

            $this->assertSame('spreadsheet::messages.unknown_value', $result->error, $text);
            $this->assertSame(['value' => $text], $result->errorParams);
            $this->assertNull($result->value);
        }
    }

    public function test_aliases_come_from_config(): void
    {
        config(['spreadsheet.import.aliases.language' => ['Magyar nyelv' => 'hu']]);

        $this->assertSame('hu', $this->language('magyar nyelv')->value);
    }

    public function test_blank_is_null(): void
    {
        $this->assertNull($this->language(null)->value);
        $this->assertNull($this->language(' ')->error);
    }

    private function language(mixed $cell, ?NormalizationContext $context = null): NormalizedValue
    {
        return (new LanguageNormalizer)->normalize($cell, Field::make('l', FieldType::Language), $context ?? new NormalizationContext);
    }
}
