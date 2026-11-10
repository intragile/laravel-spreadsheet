<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Walks an Eloquent result set with keyset pagination.
 *
 * Offset pagination is not stable: rows that tie on the sort column may change order between
 * pages, and rows inserted mid-run shift every later page. Keyset pagination remembers the
 * last emitted row's values instead of a row count, and pairs the sort column with the primary
 * key so the ordering is total.
 *
 * NULLs are handled in two phases rather than through driver-specific ordering: first every
 * row with a non-NULL sort value, then every row where it is NULL (ordered by key). The result
 * is identical on every database and both phases can use an index on (sort, key).
 *
 * The key is always compared table-qualified, and a builder without explicit columns selects
 * only the model table's columns, so joins and relation queries (BelongsToMany, HasManyThrough)
 * neither make the key ambiguous nor overwrite model attributes with joined columns. An
 * unqualified sort column of a joined query is qualified with the model table when it is one
 * of that table's columns; a selected alias is used as is.
 */
final class KeysetCursor
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $queryFactory  returns a fresh, filtered builder on every call
     * @param  string|null  $sortColumn  a column of the model table (optionally table-qualified) or a selected alias
     * @return Generator<int, TModel>
     */
    public static function each(Closure $queryFactory, ?string $sortColumn = null, string $direction = 'asc', int $chunkSize = 1000): Generator
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $operator = $direction === 'desc' ? '<' : '>';
        $chunkSize = max(1, $chunkSize);
        $index = 0;

        $first = self::prepare($queryFactory());
        $model = $first->getModel();
        $keyColumn = $model->getQualifiedKeyName();

        // SQL expression of the sort column and the attribute its value is read from.
        $sortExpression = null;
        $sortAttribute = null;

        if ($sortColumn !== null) {
            $dot = strrpos($sortColumn, '.');
            $sortAttribute = $dot === false ? $sortColumn : substr($sortColumn, $dot + 1);
            $isKey = $sortAttribute === $model->getKeyName() && ($dot === false || $sortColumn === $keyColumn);

            if ($isKey) {
                $sortAttribute = null;
            } else {
                $sortExpression = $dot === false ? self::qualify($first, $sortColumn) : $sortColumn;
            }
        }

        $phases = $sortExpression === null ? [false] : [true, false];

        foreach ($phases as $sorted) {
            $lastSort = null;
            $lastKey = null;
            $started = false;

            while (true) {
                $query = ($first ?? self::prepare($queryFactory()))->reorder();
                $first = null;

                if ($sortExpression === null) {
                    $query->orderBy($keyColumn, $direction);
                } elseif ($sorted) {
                    $query->whereNotNull($sortExpression)
                        ->orderBy($sortExpression, $direction)
                        ->orderBy($keyColumn, $direction);
                } else {
                    $query->whereNull($sortExpression)->orderBy($keyColumn, $direction);
                }

                if ($started) {
                    if ($sorted && $sortExpression !== null) {
                        $query->where(function (Builder $sub) use ($sortExpression, $keyColumn, $operator, $lastSort, $lastKey): void {
                            $sub->where($sortExpression, $operator, $lastSort)
                                ->orWhere(function (Builder $tie) use ($sortExpression, $keyColumn, $operator, $lastSort, $lastKey): void {
                                    $tie->where($sortExpression, '=', $lastSort)->where($keyColumn, $operator, $lastKey);
                                });
                        });
                    } else {
                        $query->where($keyColumn, $operator, $lastKey);
                    }
                }

                $chunk = $query->limit($chunkSize)->get();

                foreach ($chunk as $row) {
                    // Raw value: a cast (e.g. datetime to Carbon) would change the format used
                    // in the next comparison.
                    $lastSort = $sorted && $sortAttribute !== null ? $row->getRawOriginal($sortAttribute) : null;

                    if ($sorted && $lastSort === null) {
                        throw new LogicException(
                            "The sort column \"{$sortColumn}\" is missing from the fetched rows; it must be selected, as \"{$sortAttribute}\" or an alias of that name.",
                        );
                    }

                    yield $index++ => $row;

                    $lastKey = $row->getKey();
                    $started = true;
                }

                if ($chunk->count() < $chunkSize) {
                    break;
                }
            }
        }
    }

    /**
     * Restricts a builder without explicit columns to the model table's columns: a join (or the
     * pivot join of a relation query) would otherwise overwrite the key and other attributes.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private static function prepare(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->qualifyColumn('*'));
        }

        return $query;
    }

    /**
     * Qualifies an unqualified sort column with the model table when the query joins other
     * tables and the column belongs to the model table; aliases and columns of joined tables
     * are left as they are.
     *
     * @param  Builder<covariant Model>  $query
     */
    private static function qualify(Builder $query, string $column): string
    {
        if (empty($query->getQuery()->joins)) {
            return $column;
        }

        $model = $query->getModel();

        return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)
            ? $model->qualifyColumn($column)
            : $column;
    }
}
