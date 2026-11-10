<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

final class PhoneNormalizer implements Normalizer
{
    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        return NormalizedValue::ok(Cell::text($value));
    }
}
