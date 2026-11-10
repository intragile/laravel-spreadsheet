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
     * @param  EloquentBuilder<*>|Builder  $query
     * @param  list<string>  $keys
     */
    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys): void;
}
