<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplate;

/**
 * The outcome of an import run. When the mapping still needs a human, the status is
 * NeedsConfirmation, every counter is zero and no row was read.
 */
final readonly class ImportResult
{
    /**
     * @param  int  $skipped  Rows left alone: in-file duplicates, matches under the Skip policy and rows sent to review.
     * @param  int  $failed  Rows that could not be imported because of an error.
     * @param  string|null  $failedRowsPath  Where the failed-rows file was stored; null when every row went through.
     * @param  MappingTemplate|null  $suggestedTemplate  A saved template with similar headers, offered but not applied.
     */
    public function __construct(
        public ImportStatus $status,
        public int $created,
        public int $updated,
        public int $skipped,
        public int $failed,
        public ?string $failedRowsDisk,
        public ?string $failedRowsPath,
        public ColumnMapping $mapping,
        public ?MappingTemplate $suggestedTemplate = null,
    ) {}
}
