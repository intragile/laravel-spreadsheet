<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\FailedRows;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowStatus;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Contracts\Writer;
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
 */
final class FailedRowsWriter
{
    private ?Writer $writer = null;

    private ?string $base = null;

    private ?string $path = null;

    private int $rows = 0;

    private readonly Format $format;

    /**
     * @param  list<string>  $headers  Original header texts, one per column.
     * @param  list<int>  $sensitiveColumns  Columns whose values are blanked.
     */
    public function __construct(
        private readonly array $headers,
        Format $format,
        private readonly array $sensitiveColumns,
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

        $line[] = Cell::text($status->label());
        $line[] = Cell::text($message);

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
            $writer = Spreadsheet::write($this->path, $this->format);
            $writer->sheet('Failed rows', new SheetOptions(freezeRows: 1));
            $writer->addRow([
                ...array_map(static fn (string $header): Cell => Cell::text($header), $this->headers),
                Cell::text((string) __('spreadsheet::messages.failed_rows.status_header')),
                Cell::text((string) __('spreadsheet::messages.failed_rows.message_header')),
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
            is_string($value) => Cell::text($value),
            $value === null, is_int($value), is_float($value), is_bool($value), $value instanceof DateTimeInterface => $value,
            default => Cell::text($value instanceof Stringable ? (string) $value : ''),
        };
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
