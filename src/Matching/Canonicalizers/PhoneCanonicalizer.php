<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/** Keeps digits only; formatting characters in stored values are stripped by the prefilter. */
final class PhoneCanonicalizer implements Canonicalizer
{
    use PrefiltersBySqlExpression;

    public function canonicalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $key = (string) preg_replace('/\D+/', '', (string) $value);

        return $key === '' ? null : $key;
    }

    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys, array $rawValues = []): void
    {
        $this->whereExpressionIn(
            $query,
            $column,
            $keys,
            fn (string $c): string => $this->stripCharacters($c, ' ', '-', '+', '(', ')', '/', '.'),
        );
    }
}
