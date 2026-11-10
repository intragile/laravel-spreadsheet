<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\FailedRows;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowStatus;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Contracts\Writer;
use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Streams the rows that could not be imported into a file in the format of the upload: the
 * original headers, the original cell values and two trailing columns with status and message.
 * The file is created lazily, so an import without failures leaves nothing behind.
 *
 * A CSV file keeps the upload's delimiter and is written as UTF-8 with a byte order mark, so
 * spreadsheet applications show accents correctly whatever the upload's encoding was. A text
 * value that a spreadsheet application would evaluate (it starts with "=", "+", "-", "@", a tab
 * or a carriage return) gets a leading apostrophe, unless it is a signed number or phone number:
 * a sign followed only by digits, spaces and the characters . , ( ) / - cannot call a function
 * or reference a cell, so "-12,5" and "+36 1 234 5678" stay as they are.
 */
final class FailedRowsWriter
{
    /** First characters that make a spreadsheet application evaluate a field. */
    private const FORMULA_TRIGGERS = "=+-@\t\r";

    /** A signed number or phone number, harmless when evaluated. */
    private const SIGNED_NUMBER = '~^[+-][\d .,()/\x{00A0}-]*\d[\d .,()/\x{00A0}-]*$~u';

    private ?Writer $writer = null;

    private ?string $base = null;

    private ?string $path = null;

    private int $rows = 0;

    private readonly Format $format;

    /**
     * @param  list<string>  $headers  Original header texts, one per column.
     * @param  list<int>  $sensitiveColumns  Columns whose values are blanked.
     * @param  string  $csvDelimiter  Field delimiter of a CSV file, normally the upload's.
     */
    public function __construct(
        private readonly array $headers,
        Format $format,
        private readonly array $sensitiveColumns,
        private readonly string $csvDelimiter = ',',
    ) {
        $this->format = $format === Format::Xls ? Format::Xlsx : $format;
    }

    /** @param  array<int, mixed>  $cells */
    public function write(array $cells, RowStatus $status, string $message): void
    {
        $writer = $this->writer ??= $this->open();
        /** @var list<bool|DateTimeInterface|float|int|Cell|string|null> $line */
        $line = [];

        foreach ($this->headers as $column => $_) {
            $line[] = in_array($column, $this->sensitiveColumns, true) ? null : $this->cell($cells[$column] ?? null);
        }

        $line[] = $this->text($status->label());
        $line[] = $this->text($message);

        $writer->addRow($line);
        $this->rows++;
    }

    public function count(): int
    {
        return $this->rows;
    }

    /** @return string|null The stored path, or null when no row was written. */
    public function finish(?string $disk, string $directory): ?string
    {
        if ($this->writer === null || $this->path === null) {
            $this->cleanup();

            return null;
        }

        try {
            $this->writer->close();
            $this->writer = null;

            $stream = fopen($this->path, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Cannot read the failed-rows file.');
            }

            $target = trim($directory, '/').'/'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.'.$this->format->value;

            try {
                if (Storage::disk($disk)->writeStream($target, $stream) === false) {
                    throw new RuntimeException("Cannot store the failed-rows file at [{$target}].");
                }
            } finally {
                fclose($stream);
            }

            return $target;
        } finally {
            $this->cleanup();
        }
    }

    public function abort(): void
    {
        $this->cleanup();
    }

    private function open(): Writer
    {
        $base = tempnam(sys_get_temp_dir(), 'spreadsheet-failed-');

        if ($base === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $this->base = $base;
        $this->path = $base.'.'.$this->format->value;

        try {
            $writer = $this->format === Format::Csv
                ? new CsvWriter($this->path, $this->csvDelimiter, bom: true)
                : Spreadsheet::write($this->path, $this->format);
            $writer->sheet('Failed rows', new SheetOptions(freezeRows: 1, autoFilter: true));
            $writer->addRow([
                ...array_map(fn (string $header): Cell => $this->text($header), $this->headers),
                $this->text((string) __('spreadsheet::messages.failed_rows.status_header')),
                $this->text((string) __('spreadsheet::messages.failed_rows.message_header')),
            ]);
        } catch (Throwable $e) {
            $this->cleanup();

            throw $e;
        }

        return $writer;
    }

    private function cell(mixed $value): bool|DateTimeInterface|float|int|Cell|null
    {
        return match (true) {
            is_string($value) => $this->text($value),
            $value === null, is_int($value), is_float($value), is_bool($value), $value instanceof DateTimeInterface => $value,
            default => $this->text($value instanceof Stringable ? (string) $value : ''),
        };
    }

    /** A text cell; in a CSV file a value that would be evaluated as a formula is escaped. */
    private function text(string $value): Cell
    {
        return Cell::text($this->format === Format::Csv ? self::escapeFormula($value) : $value);
    }

    private static function escapeFormula(string $value): string
    {
        if ($value === '' || ! str_contains(self::FORMULA_TRIGGERS, $value[0]) || preg_match(self::SIGNED_NUMBER, $value) === 1) {
            return $value;
        }

        return "'".$value;
    }

    private function cleanup(): void
    {
        if ($this->writer !== null) {
            try {
                $this->writer->close();
            } catch (Throwable) {
            }
            $this->writer = null;
        }

        foreach ([$this->path, $this->base] as $file) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }

        $this->path = null;
        $this->base = null;
    }
}
