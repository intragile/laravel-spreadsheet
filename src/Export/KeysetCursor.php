<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use Closure;
use Generator;
use Illuminate\Contracts\Database\Query\Expression;
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
 * neither make the key ambiguous nor overwrite model attributes with joined columns. The sort
 * column is a selected column alias (compared through the column behind it), a column of the model
 * table (qualified with the model table when the query joins others), or that column qualified
 * with the model table; a column of a joined table must be selected under an alias. An unaliased
 * joined column in the select list that would overwrite the key or the sort attribute is refused;
 * a `*` of a joined table (or `select('*')`) cannot be checked and must not be combined with a
 * key or sort column the joined table also has.
 */
final class KeysetCursor
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $queryFactory  returns a fresh, filtered builder on every call
     * @param  string|null  $sortColumn  a selected alias, or a column of the model table (optionally table-qualified)
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
        $foreign = self::foreignColumns($first);

        if (isset($foreign[$model->getKeyName()])) {
            throw new LogicException(
                "The selected column \"{$foreign[$model->getKeyName()]}\" overwrites the model key in the fetched rows; select it under an alias.",
            );
        }

        // SQL expression of the sort column and the attribute its value is read from.
        $sortExpression = null;
        $sortAttribute = null;

        if ($sortColumn !== null) {
            [$sortExpression, $sortAttribute] = self::resolveSort($first, $sortColumn, $foreign);
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
     * The SQL column to sort and compare by, and the attribute of a fetched row holding its
     * value; [null, null] when the sort column is the primary key.
     *
     * A selected column alias sorts by the column behind it, so the comparison never depends on
     * a database resolving aliases in WHERE. A column of the model table may be table-qualified;
     * a column of another table must be selected under an alias, because only the alias tells
     * which attribute of the fetched row holds its value. A raw-expression alias is refused: it
     * cannot be compared portably.
     *
     * @param  Builder<covariant Model>  $query
     * @param  array<string, string>  $foreign  Unaliased columns of other tables, by base name.
     * @return array{0: string|null, 1: string|null}
     */
    private static function resolveSort(Builder $query, string $column, array $foreign): array
    {
        $model = $query->getModel();
        $aliases = self::aliases($query);

        if (! str_contains($column, '.') && array_key_exists($column, $aliases)) {
            if ($aliases[$column] === null) {
                throw new LogicException("The sort alias \"{$column}\" names a raw expression, which cannot be compared portably; sort by a column or a plain column alias.");
            }

            return [$aliases[$column], $column];
        }

        $dot = strrpos($column, '.');
        $attribute = $dot === false ? $column : substr($column, $dot + 1);

        if ($dot !== false && substr($column, 0, $dot) !== $model->getTable()) {
            throw new LogicException(
                "The sort column \"{$column}\" belongs to another table than the model's; select it under an alias, for example addSelect('{$column} as sort_value'), and sort by the alias.",
            );
        }

        if (array_key_exists($attribute, $aliases)) {
            throw new LogicException("The sort column \"{$column}\" is shadowed by the selected alias \"{$attribute}\"; sort by the alias or rename it.");
        }

        if (isset($foreign[$attribute])) {
            throw new LogicException(
                "The sort column \"{$column}\" collides with the selected column \"{$foreign[$attribute]}\", which overwrites it in the fetched rows; select that column under an alias.",
            );
        }

        if ($attribute === $model->getKeyName()) {
            return [null, null];
        }

        return [$dot === false ? self::qualify($query, $column) : $column, $attribute];
    }

    /**
     * Aliased columns of the select clause: alias => the column behind it, or null for a raw
     * expression.
     *
     * @param  Builder<covariant Model>  $query
     * @return array<string, string|null>
     */
    private static function aliases(Builder $query): array
    {
        $base = $query->getQuery();
        $aliases = [];

        foreach ($base->columns ?? [] as $column) {
            $raw = $column instanceof Expression;
            $text = $raw ? (string) $column->getValue($base->getGrammar()) : $column;

            if (preg_match('/^(.+)\s+as\s+[`"\[]?([^`"\]\s.]+)[`"\]]?$/is', trim($text), $m) === 1) {
                $aliases[$m[2]] = $raw ? null : $m[1];
            }
        }

        return $aliases;
    }

    /**
     * Unaliased, table-qualified columns of other tables in the select clause, by base name: a
     * fetched row holds their value under the base name, overwriting a model attribute of the
     * same name. A `*` of a joined table (or a bare `*`) cannot be resolved without the schema
     * and is not reported.
     *
     * @param  Builder<covariant Model>  $query
     * @return array<string, string>
     */
    private static function foreignColumns(Builder $query): array
    {
        $table = $query->getModel()->getTable();
        $columns = [];

        foreach ($query->getQuery()->columns ?? [] as $column) {
            if (! is_string($column) || preg_match('/\s+as\s+/i', $column) === 1) {
                continue;
            }

            $dot = strrpos($column, '.');

            if ($dot !== false && substr($column, 0, $dot) !== $table && substr($column, $dot + 1) !== '*') {
                $columns[substr($column, $dot + 1)] = $column;
            }
        }

        return $columns;
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
