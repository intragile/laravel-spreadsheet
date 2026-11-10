<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use IntrAgile\LaravelSpreadsheet\Import\Import;

/**
 * Finds the existing records that imported rows correspond to.
 */
interface RecordMatcher
{
    /**
     * Matches one chunk of rows. Rows without a match are absent from the result.
     *
     * @param  array<int, array<string, mixed>>  $valuesByRow  Row number => field values keyed by field key.
     * @return array<int, MatchResult> Row number => the first matching rule's result.
     */
    public function match(Import $import, array $valuesByRow): array;
}
