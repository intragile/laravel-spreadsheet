<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization;

/**
 * Per-column settings that resolve ambiguity in raw values: the locales whose names are
 * accepted, the date layout (for example "d/m/Y") and the decimal separator of the column.
 */
final readonly class NormalizationContext
{
    /** @param  list<string>  $locales */
    public function __construct(
        public array $locales = ['en'],
        public ?string $dateFormat = null,
        public ?string $decimalSeparator = null,
    ) {}
}
