<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowStatus;

/**
 * What one attempt at a chunk produced, held back until the attempt has committed.
 *
 * @internal
 */
final class ChunkOutcome
{
    public int $created = 0;

    public int $updated = 0;

    /** Rows skipped without an entry in the failed-rows file. */
    public int $skipped = 0;

    /** @var array<int, array{0: RowStatus, 1: string, 2: array<int, mixed>}> Row number => failed-rows entry. */
    public array $failures = [];

    /** @var list<array{0: string, 1: string, 2: int}> [rule id, key, row number] of the records created. */
    public array $createdKeys = [];

    /** @param  array<int, mixed>  $cells */
    public function fail(int $number, RowStatus $status, string $message, array $cells): void
    {
        $this->failures[$number] = [$status, $message, $cells];
    }
}
