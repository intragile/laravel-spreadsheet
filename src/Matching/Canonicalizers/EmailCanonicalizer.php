<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

final class EmailCanonicalizer implements Canonicalizer
{
    use PrefiltersBySqlExpression;

    public function canonicalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $key = mb_strtolower(trim((string) $value));

        return $key === '' ? null : $key;
    }

    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys): void
    {
        $this->whereExpressionIn($query, $column, $keys, fn (string $c): string => "lower(trim({$c}))");
    }
}
