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
     * SQL cannot fold accents portably, so the condition compares the lower-cased, trimmed
     * stored value with the raw cell values (lower-cased, whitespace collapsed) rather than
     * the folded keys; an exact match on the trimmed raw values covers non-ASCII case
     * differences on engines whose LOWER() is ASCII-only. Rows that differ only by accents
     * are found only when stored values are already folded or a custom matcher is used.
     */
    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys, array $rawValues = []): void
    {
        $source = $rawValues === [] ? $keys : $rawValues;
        $comparable = array_values(array_unique(array_map(
            fn (string $value): string => $this->collapse(mb_strtolower($value)),
            $source,
        )));

        $this->whereExpressionIn($query, $column, $comparable, fn (string $c): string => "lower(trim({$c}))", $rawValues);
    }

    private function collapse(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
