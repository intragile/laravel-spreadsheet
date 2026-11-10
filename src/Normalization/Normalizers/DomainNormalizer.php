<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\DomainCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;
use InvalidArgumentException;

final class DomainNormalizer implements Normalizer
{
    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        try {
            return NormalizedValue::ok(DomainCanonicalizer::normalize($text));
        } catch (InvalidArgumentException) {
            return NormalizedValue::error('spreadsheet::messages.invalid_domain', ['value' => $text]);
        }
    }
}
