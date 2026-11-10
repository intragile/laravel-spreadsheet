<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization;

use Closure;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
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
use InvalidArgumentException;

/** Resolves the normalizer of a field: its own override first, then the default of its type. */
final class NormalizerRegistry
{
    /** @var array<string, Normalizer> */
    private array $byType = [];

    public function __construct()
    {
        $date = new DateNormalizer;
        $number = new NumberNormalizer;

        $this->byType = [
            FieldType::Text->value => new TextNormalizer,
            FieldType::Email->value => new EmailNormalizer,
            FieldType::Phone->value => new PhoneNormalizer,
            FieldType::Date->value => $date,
            FieldType::DateTime->value => $date,
            FieldType::Integer->value => $number,
            FieldType::Decimal->value => $number,
            FieldType::Boolean->value => new BooleanNormalizer,
            FieldType::Enum->value => new EnumNormalizer,
            FieldType::Country->value => new CountryNormalizer,
            FieldType::Currency->value => new CurrencyNormalizer,
            FieldType::Language->value => new LanguageNormalizer,
            FieldType::Domain->value => new DomainNormalizer,
        ];
    }

    public function for(Field $field): Normalizer
    {
        $override = $field->normalizer();

        if ($override instanceof Closure) {
            return new ClosureNormalizer($override);
        }

        return $override ?? $this->byType[$field->type->value];
    }

    /** Replaces the default normalizer of a field type. */
    public function extend(FieldType|string $type, Normalizer $normalizer): void
    {
        $key = $type instanceof FieldType ? $type->value : $type;

        if (! isset($this->byType[$key])) {
            throw new InvalidArgumentException("Unknown field type [{$key}].");
        }

        $this->byType[$key] = $normalizer;
    }
}
