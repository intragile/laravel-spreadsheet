<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

/** Matches the option value, or a label in any configured locale, ignoring case and accents. */
final class EnumNormalizer implements Normalizer
{
    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        $folded = Cell::fold($text);
        $locales = [...$context->locales, Field::ANY_LOCALE];

        foreach ($field->optionMap() as $option => $labels) {
            if (Cell::fold((string) $option) === $folded) {
                return NormalizedValue::ok($option);
            }

            foreach ($locales as $locale) {
                if (isset($labels[$locale]) && Cell::fold($labels[$locale]) === $folded) {
                    return NormalizedValue::ok($option);
                }
            }
        }

        return NormalizedValue::error('spreadsheet::messages.unknown_option', [
            'value' => $text,
            'options' => implode(', ', $this->labels($field, $context)),
        ]);
    }

    /** @return list<string> */
    private function labels(Field $field, NormalizationContext $context): array
    {
        $locales = [...$context->locales, Field::ANY_LOCALE];
        $labels = [];

        foreach ($field->optionMap() as $option => $byLocale) {
            $label = null;

            foreach ($locales as $locale) {
                $label ??= $byLocale[$locale] ?? null;
            }

            $labels[] = $label ?? (string) $option;
        }

        return $labels;
    }
}
