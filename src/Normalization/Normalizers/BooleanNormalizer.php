<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

/** Accepts native booleans, 1/0 and the accent-insensitive words of spreadsheet.import.boolean. */
final class BooleanNormalizer implements Normalizer
{
    /** @var array<string, array{true: array<string, true>, false: array<string, true>}> */
    private array $words = [];

    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        if (is_bool($value)) {
            return NormalizedValue::ok($value);
        }

        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        $words = $this->words();
        $folded = Cell::fold($text);

        if (isset($words['true'][$folded])) {
            return NormalizedValue::ok(true);
        }

        if (isset($words['false'][$folded])) {
            return NormalizedValue::ok(false);
        }

        return NormalizedValue::error('spreadsheet::messages.invalid_boolean', ['value' => $text]);
    }

    /** @return array{true: array<string, true>, false: array<string, true>} */
    private function words(): array
    {
        $true = (array) config('spreadsheet.import.boolean.true', []);
        $false = (array) config('spreadsheet.import.boolean.false', []);
        $signature = serialize([$true, $false]);

        return $this->words[$signature] ??= [
            'true' => self::set($true),
            'false' => self::set($false),
        ];
    }

    /**
     * @param  array<mixed>  $words
     * @return array<string, true>
     */
    private static function set(array $words): array
    {
        $set = [];

        foreach ($words as $word) {
            if (is_scalar($word)) {
                $set[Cell::fold((string) $word)] = true;
            }
        }

        return $set;
    }
}
