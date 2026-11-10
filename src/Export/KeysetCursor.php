<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
 */
final class KeysetCursor
{
    /**
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $queryFactory  returns a fresh, filtered builder on every call
     * @return Generator<int, TModel>
     */
    public static function each(Closure $queryFactory, ?string $sortColumn = null, string $direction = 'asc', int $chunkSize = 1000): Generator
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $operator = $direction === 'desc' ? '<' : '>';
        $chunkSize = max(1, $chunkSize);
        $index = 0;

        $phases = $sortColumn === null ? [false] : [true, false];

        foreach ($phases as $sorted) {
            $lastSort = null;
            $lastKey = null;
            $started = false;
            $keyOnly = false;

            while (true) {
                $query = $queryFactory()->reorder();
                $keyName = $query->getModel()->getKeyName();

                if ($sortColumn === $keyName) {
                    $sortColumn = null;
                    $sorted = false;
                    $keyOnly = true;
                }

                if ($sorted && $sortColumn !== null) {
                    $query->whereNotNull($sortColumn)
                        ->orderBy($sortColumn, $direction)
                        ->orderBy($keyName, $direction);
                } else {
                    if ($sortColumn !== null) {
                        $query->whereNull($sortColumn);
                    }
                    $query->orderBy($keyName, $direction);
                }

                if ($started) {
                    if ($sorted && $sortColumn !== null) {
                        $query->where(function (Builder $sub) use ($sortColumn, $keyName, $operator, $lastSort, $lastKey): void {
                            $sub->where($sortColumn, $operator, $lastSort)
                                ->orWhere(function (Builder $tie) use ($sortColumn, $keyName, $operator, $lastSort, $lastKey): void {
                                    $tie->where($sortColumn, '=', $lastSort)->where($keyName, $operator, $lastKey);
                                });
                        });
                    } else {
                        $query->where($keyName, $operator, $lastKey);
                    }
                }

                $chunk = $query->limit($chunkSize)->get();

                foreach ($chunk as $model) {
                    yield $index++ => $model;

                    // Raw value: a cast (e.g. datetime to Carbon) would change the format used
                    // in the next comparison.
                    $lastSort = $sorted && $sortColumn !== null ? $model->getRawOriginal($sortColumn) : null;
                    $lastKey = $model->getKey();
                    $started = true;
                }

                if ($chunk->count() < $chunkSize) {
                    break;
                }
            }

            if ($keyOnly) {
                return;
            }
        }
    }
}
