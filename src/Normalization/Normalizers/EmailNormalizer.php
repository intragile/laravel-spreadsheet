<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

/** Trims the address and lower-cases its domain part; the local part keeps its case. */
final class EmailNormalizer implements Normalizer
{
    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $text = Cell::text($value);

        if ($text === null || ($at = strrpos($text, '@')) === false) {
            return NormalizedValue::ok($text);
        }

        return NormalizedValue::ok(substr($text, 0, $at + 1).mb_strtolower(substr($text, $at + 1)));
    }
}
