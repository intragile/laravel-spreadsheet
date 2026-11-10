<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use IntrAgile\LaravelSpreadsheet\Support\AccentFolder;

final class TextCanonicalizer implements Canonicalizer
{
    use PrefiltersBySqlExpression;

    public function canonicalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $key = $this->collapse(AccentFolder::fold(mb_strtolower((string) $value)));

        return $key === '' ? null : $key;
    }

    /**
     * Compares the lower-cased, trimmed stored value with the keys (lower-cased, whitespace
     * collapsed). SQL cannot fold accents portably, so rows that differ from a key only by
     * accents or inner whitespace are found only when the stored values are already in that
     * form or a custom matcher is used.
     */
    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys): void
    {
        $keys = array_values(array_unique(array_map(
            fn (string $key): string => $this->collapse(mb_strtolower($key)),
            $keys,
        )));

        $this->whereExpressionIn($query, $column, $keys, fn (string $c): string => "lower(trim({$c}))");
    }

    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
