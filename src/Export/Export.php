<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Storage;
use IntrAgile\Spreadsheet\Style;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A streaming export of an Eloquent query or any iterable to xlsx, ods or csv.
 *
 * Use it fluently (`Export::fromQuery($query)->withColumns([...])->download('a.xlsx')`) or
 * extend it and override query() or rows() and columns(); only the class-based form can be queued.
 */
class Export
{
    final public function __construct() {}

    /** @var Builder<*>|Relation<*, *, *>|null */
    private Builder|Relation|null $fluentQuery = null;

    /** @var iterable<mixed>|null */
    private ?iterable $fluentRows = null;

    /** @var list<Column>|null */
    private ?array $fluentColumns = null;

    /** True once a source was supplied through the fluent API, which cannot be serialized for a queue. */
    private bool $fluent = false;

    private ?string $sortColumn = null;

    private string $sortDirection = 'asc';

    private string $sheetName = 'Export';

    private bool $headerStyleSet = false;

    private ?Style $headerStyle = null;

    private ?int $chunkSize = null;

    /** @param  Builder<*>|Relation<*, *, *>  $query */
    public static function fromQuery(Builder|Relation $query): static
    {
        $export = new static;
        $export->fluentQuery = $query;
        $export->fluent = true;

        return $export;
    }

    /** @param  iterable<mixed>  $rows */
    public static function fromRows(iterable $rows): static
    {
        $export = new static;
        $export->fluentRows = $rows;
        $export->fluent = true;

        return $export;
    }

    /**
     * Query source for class-based exports.
     *
     * @return Builder<*>|Relation<*, *, *>|null
     */
    public function query(): Builder|Relation|null
    {
        return null;
    }

    /**
     * Row source for class-based exports.
     *
     * @return iterable<mixed>|null
     */
    public function rows(): ?iterable
    {
        return null;
    }

    /**
     * Column definitions for class-based exports.
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        return [];
    }

    /** @param  list<Column>  $columns */
    public function withColumns(array $columns): static
    {
        $this->fluentColumns = $columns;
        $this->fluent = true;

        return $this;
    }

    public function sortBy(string $column, string $direction = 'asc'): static
    {
        $this->sortColumn = $column;
        $this->sortDirection = $direction;

        return $this;
    }

    public function sheet(string $name): static
    {
        $this->sheetName = $name;

        return $this;
    }

    /** Overrides the configured header style; null writes the header unstyled. */
    public function headerStyle(?Style $style): static
    {
        $this->headerStyle = $style;
        $this->headerStyleSet = true;

        return $this;
    }

    public function chunkSize(int $size): static
    {
        $this->chunkSize = max(1, $size);

        return $this;
    }

    /**
     * Writes the export to a local file; the format follows the extension.
     *
     * @return int number of data rows written
     */
    public function writeTo(string $localPath): int
    {
        $columns = $this->fluentColumns ?? $this->columns();

        if ($columns === []) {
            throw new LogicException('An export needs at least one column.');
        }

        $writer = new ExportWriter(
            $columns,
            $this->sheetName,
            $this->headerStyleSet ? $this->headerStyle : $this->configuredHeaderStyle(),
            [
                'delimiter' => config()->string('spreadsheet.export.csv.delimiter', ','),
                'bom' => config()->boolean('spreadsheet.export.csv.bom', false),
                'escape_formulas' => config()->boolean('spreadsheet.export.csv.escape_formulas', true),
            ],
        );

        return $writer->write($localPath, $this->source());
    }

    public function download(string $filename): StreamedResponse
    {
        $local = $this->tempPath($filename);

        try {
            $this->writeTo($local);
        } catch (\Throwable $e) {
            $this->discard($local);

            throw $e;
        }

        return response()->streamDownload(static function () use ($local): void {
            try {
                $handle = fopen($local, 'rb');

                if ($handle !== false) {
                    fpassthru($handle);
                    fclose($handle);
                }
            } finally {
                if (is_file($local)) {
                    unlink($local);
                }
            }
        }, $filename, [
            'Content-Type' => $this->contentType($filename),
            'Content-Length' => (string) filesize($local),
        ]);
    }

    /** Writes the export to a filesystem disk and returns the path. */
    public function store(string $path, ?string $disk = null): string
    {
        $this->storeCounted($path, $disk);

        return $path;
    }

    /**
     * @internal used by the queued job to learn the row count
     *
     * @return int number of data rows written
     */
    public function storeCounted(string $path, ?string $disk = null): int
    {
        $local = $this->tempPath($path);

        try {
            $rows = $this->writeTo($local);

            $stream = fopen($local, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Cannot read the generated export file.');
            }

            try {
                $filesystem = Storage::disk($disk);

                try {
                    if ($filesystem->writeStream($path, $stream) === false) {
                        throw new RuntimeException("Cannot write the export to \"{$path}\".");
                    }
                } catch (\Throwable $e) {
                    $filesystem->delete($path);

                    throw $e;
                }
            } finally {
                fclose($stream);
            }

            return $rows;
        } finally {
            $this->discard($local);
        }
    }

    /**
     * Writes the export on the queue. Only class-based exports can be queued, as a query builder,
     * a generator or a column closure cannot be serialized.
     */
    public function queue(string $path, ?string $disk = null): PendingDispatch
    {
        if ($this->fluent) {
            throw new LogicException('Queued exports must be a class that overrides query() or rows() and columns().');
        }

        return ExportJob::dispatch($this, $path, $disk);
    }

    /** @return iterable<mixed> */
    private function source(): iterable
    {
        $query = $this->fluentQuery ?? ($this->fluentRows === null ? $this->query() : null);

        if ($query !== null) {
            /** @var Builder<Model> $builder */
            $builder = $query instanceof Relation ? (clone $query)->getQuery() : $query;
            $chunk = $this->chunkSize ?? config()->integer('spreadsheet.export.chunk_size', 1000);

            return KeysetCursor::each(static fn (): Builder => clone $builder, $this->sortColumn, $this->sortDirection, $chunk);
        }

        $rows = $this->fluentRows ?? $this->rows();

        if ($rows === null) {
            throw new LogicException('An export needs a source: pass a query or rows, or override query() or rows().');
        }

        return $rows;
    }

    private function configuredHeaderStyle(): ?Style
    {
        $config = config('spreadsheet.export.header_style');

        if (! is_array($config) || $config === []) {
            return null;
        }

        $style = Style::make();

        if (! empty($config['bold'])) {
            $style = $style->bold();
        }
        if (! empty($config['italic'])) {
            $style = $style->italic();
        }
        if (! empty($config['wrap'])) {
            $style = $style->wrap();
        }
        if (isset($config['fill']) && is_string($config['fill'])) {
            $style = $style->fill($config['fill']);
        }
        if (isset($config['font_color']) && is_string($config['font_color'])) {
            $style = $style->fontColor($config['font_color']);
        }
        if (isset($config['align']) && is_string($config['align'])) {
            $style = $style->align($config['align']);
        }
        if (! empty($config['border'])) {
            $style = is_string($config['border']) ? $style->border($config['border']) : $style->border();
        }

        return $style;
    }

    private function tempPath(string $name): string
    {
        $base = tempnam(sys_get_temp_dir(), 'spreadsheet-export-');

        if ($base === false) {
            throw new RuntimeException('Cannot create a temporary file for the export.');
        }

        // The writer picks the format from the extension, so the temp file carries the target's.
        $local = $base.'.'.strtolower(pathinfo($name, PATHINFO_EXTENSION));
        unlink($base);

        return $local;
    }

    private function discard(string $local): void
    {
        if (is_file($local)) {
            unlink($local);
        }
    }

    private function contentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'csv', 'txt' => 'text/csv; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }
}
