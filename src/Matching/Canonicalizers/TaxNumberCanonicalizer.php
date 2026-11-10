<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/** Lower-case letters and digits only (separators dropped). */
final class TaxNumberCanonicalizer implements Canonicalizer
{
    use PrefiltersBySqlExpression;

    public function canonicalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $key = (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string) $value));

        return $key === '' ? null : $key;
    }

    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys, array $rawValues = []): void
    {
        $this->whereExpressionIn(
            $query,
            $column,
            $keys,
            fn (string $c): string => 'lower('.$this->stripCharacters($c, ' ', '-', '.', '/').')',
        );
    }
}
