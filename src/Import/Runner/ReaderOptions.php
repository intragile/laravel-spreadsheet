<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use IntrAgile\Spreadsheet\ReadOptions;

/**
 * Reader options of every pass over an uploaded file (inspection, sampling and import).
 *
 * @internal
 */
final class ReaderOptions
{
    public const DEFAULT_MAX_CELLS = 2_000_000;

    public static function fromConfig(): ReadOptions
    {
        return new ReadOptions(maxCells: self::maxCells());
    }

    /** Most cells an XLS workbook may hold, or an ODS sheet may expand to through repeats. */
    public static function maxCells(): int
    {
        return max(1, config()->integer('spreadsheet.import.max_cells', self::DEFAULT_MAX_CELLS));
    }
}
