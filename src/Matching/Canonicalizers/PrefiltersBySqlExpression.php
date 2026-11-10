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
     */
    private function whereExpressionIn(EloquentBuilder|Builder $query, string $column, array $keys, callable $expression): void
    {
        if ($keys === []) {
            $query->whereRaw(new RawSql('1 = 0'));

            return;
        }

        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $sql = $expression($base->getGrammar()->wrap($column));

        $query->whereRaw(new RawSql($sql.' in ('.implode(', ', array_fill(0, count($keys), '?')).')'), $keys);
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
