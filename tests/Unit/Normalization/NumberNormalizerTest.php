<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\NumberNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class NumberNormalizerTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function decimals(): array
    {
        return [
            'space thousands, comma decimal' => ['1 234,56', '1234.56'],
            'nbsp thousands' => ["1\u{00A0}234,56", '1234.56'],
            'comma thousands, dot decimal' => ['1,234.56', '1234.56'],
            'dot thousands, comma decimal' => ['1.234,56', '1234.56'],
            'plain' => ['1234.56', '1234.56'],
            'comma decimal' => ['12,5', '12.5'],
            'unit suffix' => ['1 500 Ft', '1500'],
            'currency prefix' => ['$ 1,500.00', '1500'],
            'negative' => ['-1 234,5', '-1234.5'],
            'multiple thousands groups' => ['1.234.567', '1234567'],
            'trailing zeros trimmed' => ['10.50', '10.5'],
            'zero' => ['0,00', '0'],
            'leading zero fraction' => ['0.05', '0.05'],
            'accounting parentheses' => ['(1 234)', '-1234'],
            'accounting with decimals' => ['(1.234,50)', '-1234.5'],
            'unicode minus' => ["\u{2212}5", '-5'],
            'en dash minus' => ["\u{2013}5", '-5'],
            'figure dash minus' => ["\u{2012}5", '-5'],
            'fullwidth minus' => ["\u{FF0D}5", '-5'],
            'small hyphen minus' => ["\u{FE63}5", '-5'],
            'trailing minus' => ['5-', '-5'],
            'euro prefix' => ['€ 12,50', '12.5'],
            'euro suffix' => ['12,50 €', '12.5'],
            'iso code suffix' => ['1 500 HUF', '1500'],
            'iso code prefix' => ['EUR 1 500', '1500'],
            'short token' => ['1 500 kr', '1500'],
            'minus before symbol' => ['-€5', '-5'],
            'native int' => [42, '42'],
            'native float' => [0.1, '0.1'],
            'native float shortest' => [1234.56, '1234.56'],
            'native large float' => [1.0E+25, '10000000000000000000000000'],
            'native small float' => [1.5E-7, '0.00000015'],
        ];
    }

    #[DataProvider('decimals')]
    public function test_decimal_values(mixed $cell, string $expected): void
    {
        $result = $this->decimal($cell);

        $this->assertNull($result->error);
        $this->assertSame($expected, $result->value);
    }

    public function test_dot_group_follows_context_separator(): void
    {
        $this->assertSame('1234', $this->decimal('1.234', new NormalizationContext(decimalSeparator: ','))->value);
        $this->assertSame('1.234', $this->decimal('1.234')->value);
        $this->assertSame('1.234', $this->decimal('1.234', new NormalizationContext(decimalSeparator: '.'))->value);
        $this->assertSame('1.234', $this->decimal('1,234', new NormalizationContext(decimalSeparator: ','))->value);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimals(): array
    {
        return [
            'uneven groups' => ['1,23,45'],
            'ambiguous comma group' => ['1,234'],
            'letters' => ['abc'],
            'embedded letters' => ['12abc34'],
            'two decimal points' => ['1.2,3.4'],
            'lone sign' => ['-'],
            'trailing word' => ['12 apples'],
            'leading word' => ['abc12'],
            'double sign' => ['-5-'],
            'sign and parentheses' => ['(-5)'],
            'unbalanced parenthesis' => ['(5'],
        ];
    }

    #[DataProvider('invalidDecimals')]
    public function test_invalid_decimals(string $text): void
    {
        $result = $this->decimal($text);

        $this->assertSame('spreadsheet::messages.invalid_number', $result->error);
        $this->assertSame(['value' => $text], $result->errorParams);
        $this->assertNull($result->value);
    }

    public function test_integer_values(): void
    {
        $this->assertSame(1500, $this->integer('1 500 Ft')->value);
        $this->assertSame(12, $this->integer('12.0')->value);
        $this->assertSame(7, $this->integer(7)->value);
        $this->assertSame(7, $this->integer(7.0)->value);
        $this->assertSame(-3, $this->integer('-3')->value);
    }

    public function test_integer_affix_rules(): void
    {
        $this->assertSame(-1234, $this->integer('(1 234)')->value);
        $this->assertSame('spreadsheet::messages.invalid_integer', $this->integer('12 apples')->error);
    }

    public function test_integer_rejects_fractions_and_overflow(): void
    {
        foreach (['12.5', '1,5', '99999999999999999999', 'x'] as $text) {
            $this->assertSame('spreadsheet::messages.invalid_integer', $this->integer($text)->error, $text);
        }

        $this->assertSame('spreadsheet::messages.invalid_integer', $this->integer(12.5)->error);
    }

    public function test_non_finite_floats_are_invalid(): void
    {
        $this->assertSame('spreadsheet::messages.invalid_number', $this->decimal(NAN)->error);
        $this->assertSame('spreadsheet::messages.invalid_number', $this->decimal(INF)->error);
    }

    public function test_blank_is_null(): void
    {
        $this->assertNull($this->decimal(null)->value);
        $this->assertNull($this->decimal(" \u{00A0}")->value);
        $this->assertNull($this->integer('')->error);
    }

    private function decimal(mixed $cell, ?NormalizationContext $context = null): NormalizedValue
    {
        return (new NumberNormalizer)->normalize($cell, Field::make('n', FieldType::Decimal), $context ?? new NormalizationContext);
    }

    private function integer(mixed $cell): NormalizedValue
    {
        return (new NumberNormalizer)->normalize($cell, Field::make('n', FieldType::Integer), new NormalizationContext);
    }
}
