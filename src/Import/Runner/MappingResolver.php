<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use DateTimeInterface;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMatch;
use IntrAgile\LaravelSpreadsheet\Mapping\HeaderFingerprint;
use IntrAgile\LaravelSpreadsheet\Mapping\MappingSuggester;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplate;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Support\HeaderNormalizer;
use IntrAgile\Spreadsheet\Inspection\FileInspector;
use IntrAgile\Spreadsheet\Inspection\Inspection;
use IntrAgile\Spreadsheet\Spreadsheet;
use InvalidArgumentException;

/**
 * Finds the sheet and header row of a file, samples its first data rows and decides the column
 * mapping: an explicit one, a saved template with the same header fingerprint, or a suggestion.
 *
 * @internal
 */
final class MappingResolver
{
    private const SAMPLE_ROWS = 50;

    public function __construct(
        private readonly MappingSuggester $suggester,
        private readonly MappingTemplateStore $templates,
    ) {}

    public function resolve(Import $import, string $localPath, ImportOptions $options): ResolvedSource
    {
        $inspection = FileInspector::inspect($localPath);
        $sheet = $options->sheet ?? $inspection->firstDataSheet ?? 0;
        [$headerRow, $headers, $samples] = $this->scan($localPath, $inspection, $sheet, $options->headerRow ?? $this->detectedHeaderRow($inspection, $sheet));

        $ignored = HeaderFingerprint::ignoredHeaders();
        $skip = array_flip($ignored);
        $fingerprint = HeaderFingerprint::of($headers, $ignored);

        // The status and message columns of a failed-rows file are never suggested as fields.
        $candidates = array_filter($headers, static fn (string $header): bool => ! isset($skip[HeaderNormalizer::normalize($header)]));
        $suggestion = $this->suggester->suggest($import, $candidates, $samples);

        $applied = null;
        $suggested = null;

        if ($options->mapping !== null) {
            $mapping = $options->mapping;
        } elseif ($options->fieldByColumn !== null) {
            $mapping = $this->fromArray($import, $suggestion, $options->fieldByColumn);
        } elseif (($applied = $this->templates->findExact($import->key(), $fingerprint)) !== null) {
            $mapping = $this->fromTemplate($import, $suggestion, $applied, $headers);
        } else {
            $mapping = $options->acceptSuggested ? $this->accept($suggestion) : $suggestion;
            $suggested = $this->templates->findSimilar($import->key(), $headers);
        }

        return new ResolvedSource($inspection->format, $sheet, $headerRow, $headers, $fingerprint, $mapping, $applied, $suggested);
    }

    private function detectedHeaderRow(Inspection $inspection, int|string $sheet): ?int
    {
        foreach ($inspection->sheets as $candidate) {
            if ($candidate->info->index === $sheet || $candidate->info->name === $sheet) {
                return $candidate->headerRow;
            }
        }

        return null;
    }

    /**
     * Reads the header row and up to SAMPLE_ROWS data rows after it. Without a known header row
     * the first non-empty row is the header.
     *
     * @return array{0: int, 1: list<string>, 2: array<int, list<mixed>>}
     */
    private function scan(string $localPath, Inspection $inspection, int|string $sheet, ?int $headerRow): array
    {
        $reader = Spreadsheet::read($localPath, null, $inspection->format);
        $headerCells = [];
        $samples = [];
        $rows = 0;
        $width = 0;

        try {
            foreach ($reader->rows($sheet) as $number => $cells) {
                $headerRow ??= $number;

                if ($number < $headerRow) {
                    continue;
                }

                if ($number === $headerRow) {
                    $headerCells = $cells;
                    $width = max($width, $this->width($cells));

                    continue;
                }

                $width = max($width, $this->width($cells));

                foreach ($cells as $column => $value) {
                    if ($value !== null && (! is_string($value) || trim($value) !== '')) {
                        $samples[$column][] = $value;
                    }
                }

                if (++$rows >= self::SAMPLE_ROWS) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        $headers = [];

        for ($column = 0; $column < $width; $column++) {
            $headers[] = $this->text($headerCells[$column] ?? null);
        }

        return [$headerRow ?? 0, $headers, $samples];
    }

    /** @param  list<mixed>  $cells */
    private function width(array $cells): int
    {
        for ($i = count($cells); $i > 0; $i--) {
            $cell = $cells[$i - 1];

            if ($cell !== null && (! is_string($cell) || trim($cell) !== '')) {
                return $i;
            }
        }

        return 0;
    }

    private function text(mixed $cell): string
    {
        return match (true) {
            is_string($cell) => $cell,
            is_int($cell), is_float($cell) => (string) $cell,
            is_bool($cell) => $cell ? 'TRUE' : 'FALSE',
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d'),
            default => '',
        };
    }

    /** Takes over the suggestion including its uncertain matches; unmapped required fields still need a human. */
    private function accept(ColumnMapping $suggestion): ColumnMapping
    {
        $mapping = $suggestion;

        foreach ($suggestion->columns as $match) {
            if ($match->confidence === ColumnMatch::REVIEW) {
                $mapping = $mapping->with($match->column, $match->field);
            }
        }

        return $mapping;
    }

    /** @param  array<int, string|null>  $fieldByColumn */
    private function fromArray(Import $import, ColumnMapping $suggestion, array $fieldByColumn): ColumnMapping
    {
        $known = $this->fieldKeys($import);
        $columns = array_flip(array_map(static fn (ColumnMatch $match): int => $match->column, $suggestion->columns));

        foreach ($fieldByColumn as $column => $field) {
            if (! isset($columns[$column])) {
                throw new InvalidArgumentException("Column {$column} is not an importable column of the file.");
            }

            if ($field !== null && ! isset($known[$field])) {
                throw new InvalidArgumentException("[{$field}] is not a field of ".$import::class.'.');
            }
        }

        $mapping = $suggestion;

        foreach ($suggestion->columns as $match) {
            $mapping = $mapping->with($match->column, $fieldByColumn[$match->column] ?? null);
        }

        return $mapping;
    }

    /**
     * Maps every column the way the template maps its header (duplicate headers by occurrence)
     * and confirms the result.
     *
     * @param  list<string>  $headers
     */
    private function fromTemplate(Import $import, ColumnMapping $suggestion, MappingTemplate $template, array $headers): ColumnMapping
    {
        $known = $this->fieldKeys($import);
        $keys = MappingTemplate::mappingKeys($headers);
        $mapping = $suggestion;

        foreach ($suggestion->columns as $match) {
            $field = $template->mapping[$keys[$match->column] ?? ''] ?? null;
            $mapping = $mapping->with($match->column, $field !== null && isset($known[$field]) ? $field : null);
        }

        return $mapping->confirm();
    }

    /** @return array<string, true> */
    private function fieldKeys(Import $import): array
    {
        return array_fill_keys(array_map(static fn (Field $field): string => $field->key, $import->fields()), true);
    }
}
