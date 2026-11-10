<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;

/**
 * The choices made on a pending import.
 *
 * @internal
 */
final readonly class ImportOptions
{
    /**
     * @param  array<int, string|null>|null  $fieldByColumn  Column position => field key; columns left out are not imported.
     * @param  string|null  $templateName  Saves the mapping as a template under this name after a completed run.
     */
    public function __construct(
        public int|string|null $sheet = null,
        public ?int $headerRow = null,
        public ?ColumnMapping $mapping = null,
        public ?array $fieldByColumn = null,
        public bool $acceptSuggested = false,
        public bool $strict = false,
        public ?int $chunkSize = null,
        public ?string $templateName = null,
    ) {}
}
