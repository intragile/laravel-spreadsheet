<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

/**
 * Remembers which keys a file has already produced, without holding them in memory.
 */
interface KeyStore
{
    /** Returns the row number that first produced the key, or null when this is the first sight (and records it). */
    public function firstSeen(string $ruleId, string $key, int $rowNumber): ?int;

    public function close(): void;
}
