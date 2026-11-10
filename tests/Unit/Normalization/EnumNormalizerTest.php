<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\EnumNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class EnumNormalizerTest extends TestCase
{
    public function test_matches_value_or_label_after_folding(): void
    {
        $field = $this->field();

        $this->assertSame('active', $this->normalize($field, 'active')->value);
        $this->assertSame('active', $this->normalize($field, 'ACTIVE')->value);
        $this->assertSame('active', $this->normalize($field, 'Active')->value);
        $this->assertSame('inactive', $this->normalize($field, ' Inactive ')->value);
    }

    public function test_labels_are_matched_for_configured_locales_only(): void
    {
        $field = $this->field();

        $this->assertSame('inactive', $this->normalize($field, 'Inaktív', ['hu'])->value);
        $this->assertSame('inactive', $this->normalize($field, 'inaktiv', ['en', 'hu'])->value);
        $this->assertSame('spreadsheet::messages.unknown_option', $this->normalize($field, 'Inaktív', ['en'])->error);
    }

    public function test_wildcard_labels_match_in_every_locale(): void
    {
        $field = Field::make('kind', FieldType::Enum)->options(['a' => 'Alpha']);

        $this->assertSame('a', $this->normalize($field, 'alpha', ['de'])->value);
    }

    public function test_numeric_option_keys_keep_their_type(): void
    {
        $field = Field::make('level', FieldType::Enum)->options([1 => 'Low', 2 => 'High']);

        $this->assertSame(2, $this->normalize($field, 'high')->value);
        $this->assertSame(1, $this->normalize($field, 1)->value);
    }

    public function test_unknown_value_lists_labels(): void
    {
        $result = $this->normalize($this->field(), 'paused', ['hu']);

        $this->assertSame('spreadsheet::messages.unknown_option', $result->error);
        $this->assertSame(['value' => 'paused', 'options' => 'Aktív, Inaktív'], $result->errorParams);
        $this->assertNull($result->value);
    }

    public function test_blank_is_null(): void
    {
        $this->assertNull($this->normalize($this->field(), null)->value);
        $this->assertNull($this->normalize($this->field(), ' ')->error);
    }

    private function field(): Field
    {
        return Field::make('status', FieldType::Enum)->options([
            'active' => ['en' => 'Active', 'hu' => 'Aktív'],
            'inactive' => ['en' => 'Inactive', 'hu' => 'Inaktív'],
        ]);
    }

    /** @param  list<string>  $locales */
    private function normalize(Field $field, mixed $cell, array $locales = ['en']): NormalizedValue
    {
        return (new EnumNormalizer)->normalize($cell, $field, new NormalizationContext(locales: $locales));
    }
}
