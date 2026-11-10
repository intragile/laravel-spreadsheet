<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use IntrAgile\LaravelSpreadsheet\Support\RawSql;

/**
 * Shared plumbing for prefilters that compare a SQL expression over a column with a key list.
 */
trait PrefiltersBySqlExpression
{
    /**
     * @param  EloquentBuilder<*>|Builder  $query
     * @param  list<string>  $keys
     * @param  callable(string): string  $expression  Receives the wrapped column.
     * @param  list<string>  $exactValues  Additionally matched verbatim against the column.
     */
    private function whereExpressionIn(EloquentBuilder|Builder $query, string $column, array $keys, callable $expression, array $exactValues = []): void
    {
        $exactValues = array_values(array_unique(array_filter(
            array_map('trim', $exactValues),
            static fn (string $value): bool => $value !== '',
        )));

        if ($keys === [] && $exactValues === []) {
            $query->whereRaw(new RawSql('1 = 0'));

            return;
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $wrapped = $base->getGrammar()->wrap($column);
        $marks = static fn (array $values): string => implode(', ', array_fill(0, count($values), '?'));

        $parts = [];

        if ($keys !== []) {
            $parts[] = $expression($wrapped).' in ('.$marks($keys).')';
        }

        if ($exactValues !== []) {
            $parts[] = $wrapped.' in ('.$marks($exactValues).')';
        }

        $query->whereRaw(new RawSql('('.implode(' or ', $parts).')'), [...$keys, ...$exactValues]);
    }

    /** Nests REPLACE(…, char, '') calls around the expression for every listed character. */
    private function stripCharacters(string $expression, string ...$characters): string
    {
        foreach ($characters as $character) {
            $expression = "replace({$expression}, '{$character}', '')";
        }

        return $expression;
    }
}
