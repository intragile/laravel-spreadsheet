<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplate;
use IntrAgile\Spreadsheet\Format;

/**
 * Where the data of a file starts and how its columns map to fields.
 *
 * @internal
 */
final readonly class ResolvedSource
{
    /**
     * @param  int  $headerRow  Real row number of the header; data starts after it (0 when the sheet is empty).
     * @param  list<string>  $headers  Header texts by column position.
     */
    public function __construct(
        public Format $format,
        public int|string $sheet,
        public int $headerRow,
        public array $headers,
        public string $fingerprint,
        public ColumnMapping $mapping,
        public ?MappingTemplate $appliedTemplate,
        public ?MappingTemplate $suggestedTemplate,
    ) {}
}
