<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Pipeline;

/** One source row after normalization and validation. */
final readonly class ProcessedRow
{
    /**
     * @param  array<int, mixed>  $cells  Original cell values by column.
     * @param  array<string, mixed>  $values  Normalized values by field key.
     * @param  list<string>  $errors  Translated messages.
     * @param  list<string>  $warnings  Translated messages.
     */
    public function __construct(
        public int $rowNumber,
        public array $cells,
        public array $values,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
