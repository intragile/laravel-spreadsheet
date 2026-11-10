<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use Carbon\CarbonImmutable;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizerRegistry;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\BooleanNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\CountryNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\CurrencyNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\DateNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\DomainNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\EmailNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\EnumNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\LanguageNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\NumberNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\PhoneNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\TextNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class NormalizerRegistryTest extends TestCase
{
    public function test_every_field_type_has_a_default(): void
    {
        $expected = [
            'text' => TextNormalizer::class,
            'email' => EmailNormalizer::class,
            'phone' => PhoneNormalizer::class,
            'date' => DateNormalizer::class,
            'date_time' => DateNormalizer::class,
            'integer' => NumberNormalizer::class,
            'decimal' => NumberNormalizer::class,
            'boolean' => BooleanNormalizer::class,
            'enum' => EnumNormalizer::class,
            'country' => CountryNormalizer::class,
            'currency' => CurrencyNormalizer::class,
            'language' => LanguageNormalizer::class,
            'domain' => DomainNormalizer::class,
        ];
        $registry = new NormalizerRegistry;

        foreach (FieldType::cases() as $type) {
            $this->assertInstanceOf($expected[$type->value], $registry->for(Field::make('f', $type)), $type->value);
        }
    }

    public function test_provider_binds_a_singleton(): void
    {
        $this->assertSame(app(NormalizerRegistry::class), app(NormalizerRegistry::class));
    }

    public function test_field_normalizer_instance_overrides_the_default(): void
    {
        $custom = new class implements Normalizer
        {
            public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
            {
                return NormalizedValue::ok('custom');
            }
        };
        $field = Field::make('f', FieldType::Date)->normalizeUsing($custom);

        $this->assertSame($custom, (new NormalizerRegistry)->for($field));
        $this->assertSame($custom, $field->normalizer());
    }

    public function test_closure_override_is_wrapped(): void
    {
        $field = Field::make('f', FieldType::Text)->normalizeUsing(
            fn (mixed $value, Field $field, NormalizationContext $context): NormalizedValue => NormalizedValue::ok(strtoupper(Cell::text($value) ?? '').'@'.$field->key.'/'.$context->locales[0]),
        );

        $normalizer = (new NormalizerRegistry)->for($field);

        $this->assertInstanceOf(Normalizer::class, $normalizer);
        $this->assertSame('ABC@f/en', $normalizer->normalize('abc', $field, new NormalizationContext)->value);
    }

    public function test_field_without_override_has_no_normalizer(): void
    {
        $this->assertNull(Field::make('f')->normalizer());
    }

    public function test_extend_replaces_a_type_default(): void
    {
        $custom = new class implements Normalizer
        {
            public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
            {
                return NormalizedValue::ok(CarbonImmutable::parse('2000-01-01'));
            }
        };
        $registry = new NormalizerRegistry;
        $registry->extend(FieldType::Date, $custom);
        $registry->extend('text', $custom);

        $this->assertSame($custom, $registry->for(Field::make('d', FieldType::Date)));
        $this->assertSame($custom, $registry->for(Field::make('t')));
        $this->assertInstanceOf(DateNormalizer::class, $registry->for(Field::make('d', FieldType::DateTime)));
    }

    public function test_extend_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NormalizerRegistry)->extend('nonsense', new TextNormalizer);
    }
}
