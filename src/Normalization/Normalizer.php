<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;

/** Turns one raw cell value into the typed value of a field. */
interface Normalizer
{
    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue;
}
