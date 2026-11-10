<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Reduces a value to a comparison key and narrows a query to rows whose stored value
 * could produce one of the given keys.
 */
interface Canonicalizer
{
    /** Returns the comparison key, or null when the value carries no key. */
    public function canonicalize(mixed $value): ?string;

    /**
     * Adds a portable (LOWER, TRIM, REPLACE only) condition that keeps every row whose
     * stored value canonicalizes to one of the keys. It may keep more rows; callers
     * confirm candidates with canonicalize().
     *
     * `$keys` holds canonical keys as returned by canonicalize(). `$rawValues` holds the
     * raw cell values of the current chunk for this field; canonicalizers that cannot
     * express their key in SQL (text) compare against these instead, and several add an
     * exact match on them because LOWER() is ASCII-only on some engines (SQLite).
     * SQL TRIM removes spaces only, not tabs or line breaks. Every key and value is a
     * binding, so callers must chunk both lists to stay under the driver's binding limit
     * (for example 2100 on SQL Server).
     *
     * @param  EloquentBuilder<*>|Builder  $query
     * @param  list<string>  $keys
     * @param  list<string>  $rawValues
     */
    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys, array $rawValues = []): void;
}
