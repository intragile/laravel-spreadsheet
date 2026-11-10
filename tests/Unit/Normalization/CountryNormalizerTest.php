<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\CountryNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

final class CountryNormalizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function resolvable(): array
    {
        return [
            'alpha2' => ['HU', 'HU'],
            'alpha2 lower' => ['hu', 'HU'],
            'alpha3' => ['HUN', 'HU'],
            'alpha3 lower' => ['hun', 'HU'],
            'english name' => ['Hungary', 'HU'],
            'english name folded' => ['  hungary ', 'HU'],
            'germany' => ['Germany', 'DE'],
            'outside europe' => ['JPN', 'JP'],
            'kosovo' => ['XK', 'XK'],
            'united states' => ['United States', 'US'],
        ];
    }

    #[DataProvider('resolvable')]
    public function test_resolves_codes_and_english_names(string $text, string $expected): void
    {
        $result = $this->country($text);

        $this->assertNull($result->error);
        $this->assertSame($expected, $result->value);
    }

    #[RequiresPhpExtension('intl')]
    public function test_resolves_localized_names_for_configured_locales(): void
    {
        $context = new NormalizationContext(locales: ['en', 'hu', 'de']);

        $this->assertSame('HU', $this->country('Magyarország', $context)->value);
        $this->assertSame('HU', $this->country('magyarorszag', $context)->value);
        $this->assertSame('HU', $this->country('Ungarn', $context)->value);
        $this->assertSame('DE', $this->country('Németország', $context)->value);
        $this->assertSame('spreadsheet::messages.unknown_value', $this->country('Ungarn')->error);
    }

    public function test_unknown_values_are_errors(): void
    {
        foreach (['ZZ', 'Narnia', 'XXX'] as $text) {
            $result = $this->country($text);

            $this->assertSame('spreadsheet::messages.unknown_value', $result->error, $text);
            $this->assertSame(['value' => $text], $result->errorParams);
            $this->assertNull($result->value);
        }
    }

    public function test_aliases_come_from_config(): void
    {
        config(['spreadsheet.import.aliases.country' => ['Hungarian Republic' => 'HU', 'uk' => 'GB']]);

        $this->assertSame('HU', $this->country('hungarian republic')->value);
        $this->assertSame('GB', $this->country('UK')->value);
    }

    public function test_blank_is_null(): void
    {
        $this->assertNull($this->country(null)->value);
        $this->assertNull($this->country('')->error);
    }

    private function country(mixed $cell, ?NormalizationContext $context = null): NormalizedValue
    {
        return (new CountryNormalizer)->normalize($cell, Field::make('c', FieldType::Country), $context ?? new NormalizationContext);
    }
}
