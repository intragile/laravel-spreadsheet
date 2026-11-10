<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\CurrencyNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class CurrencyNormalizerTest extends TestCase
{
    public function test_codes_are_case_insensitive(): void
    {
        foreach (['HUF', 'huf', ' Huf '] as $text) {
            $result = $this->currency($text);

            $this->assertSame('HUF', $result->value, $text);
            $this->assertNull($result->error);
            $this->assertNull($result->warning);
        }

        $this->assertSame('JPY', $this->currency('JPY')->value);
    }

    /** @return array<string, array{string, string}> */
    public static function symbols(): array
    {
        return ['forint' => ['Ft', 'HUF'], 'euro' => ['€', 'EUR'], 'dollar' => ['$', 'USD'], 'pound' => ['£', 'GBP']];
    }

    #[DataProvider('symbols')]
    public function test_symbols_resolve_with_a_warning(string $symbol, string $code): void
    {
        $result = $this->currency($symbol);

        $this->assertSame($code, $result->value);
        $this->assertNull($result->error);
        $this->assertSame('spreadsheet::messages.currency_from_symbol', $result->warning);
        $this->assertSame(['value' => $symbol, 'code' => $code], $result->warningParams);
    }

    public function test_unknown_codes_are_errors(): void
    {
        foreach (['XYZ', 'abc', 'Monopoly'] as $text) {
            $result = $this->currency($text);

            $this->assertSame('spreadsheet::messages.unknown_value', $result->error, $text);
            $this->assertSame(['value' => $text], $result->errorParams);
            $this->assertNull($result->value);
        }
    }

    public function test_english_names_and_aliases(): void
    {
        $this->assertSame('HUF', $this->currency('Hungarian Forint')->value);
        $this->assertSame('EUR', $this->currency('euro')->value);

        config(['spreadsheet.import.aliases.currency' => ['forint' => 'HUF']]);

        $this->assertSame('HUF', $this->currency('Forint')->value);
    }

    public function test_symbols_come_from_config(): void
    {
        config(['spreadsheet.import.currency_symbols' => ['zł' => 'PLN']]);

        $this->assertSame('PLN', $this->currency('zł')->value);
        $this->assertSame('spreadsheet::messages.unknown_value', $this->currency('€')->error);
    }

    public function test_blank_is_null(): void
    {
        $this->assertNull($this->currency(null)->value);
        $this->assertNull($this->currency(' ')->error);
    }

    private function currency(mixed $cell): NormalizedValue
    {
        return (new CurrencyNormalizer)->normalize($cell, Field::make('c', FieldType::Currency), new NormalizationContext);
    }
}
