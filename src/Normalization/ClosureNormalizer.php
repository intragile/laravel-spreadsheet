<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization;

use Closure;
use IntrAgile\LaravelSpreadsheet\Import\Field;

/** Adapts a closure to the Normalizer interface. */
final readonly class ClosureNormalizer implements Normalizer
{
    /** @param  Closure(mixed, Field, NormalizationContext): NormalizedValue  $callback */
    public function __construct(private Closure $callback) {}

    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        return ($this->callback)($value, $field, $context);
    }
}
