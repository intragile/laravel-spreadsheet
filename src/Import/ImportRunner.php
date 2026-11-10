<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

use DateTimeImmutable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Exceptions\ImportLimitExceeded;
use IntrAgile\LaravelSpreadsheet\Exceptions\MappingNeedsConfirmation;
use IntrAgile\LaravelSpreadsheet\Import\FailedRows\FailedRowsWriter;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowProcessor;
use IntrAgile\LaravelSpreadsheet\Import\Runner\ChunkImporter;
use IntrAgile\LaravelSpreadsheet\Import\Runner\ImportOptions;
use IntrAgile\LaravelSpreadsheet\Import\Runner\MappingResolver;
use IntrAgile\LaravelSpreadsheet\Import\Runner\ReaderOptions;
use IntrAgile\LaravelSpreadsheet\Import\Runner\ResolvedSource;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplate;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Matching\MatchKey;
use IntrAgile\LaravelSpreadsheet\Matching\SqliteKeyStore;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizerRegistry;
use IntrAgile\Spreadsheet\Csv\CsvSniffer;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\Spreadsheet;
use RuntimeException;
use Throwable;

/**
 * Runs an import: resolves the column mapping, and once it needs no confirmation streams the
 * rows in chunks through validation, duplicate detection and the database. Rows that could not
 * be imported go to a failed-rows file in the upload's format.
 */
final class ImportRunner
{
    private const TEMP_PREFIX = 'spreadsheet-import-';

    public function __construct(
        private readonly MappingResolver $resolver,
        private readonly MappingTemplateStore $templates,
        private readonly NormalizerRegistry $normalizers,
        private readonly ValidationFactory $validator,
        private readonly MatchKey $matchKey,
    ) {}

    /** The mapping the run would use, without reading any data row. */
    public function inspect(Import $import, string $path, ?string $disk, ImportOptions $options): ColumnMapping
    {
        return $this->withLocalFile($path, $disk, fn (string $local): ColumnMapping => $this->resolver->resolve($import, $local, $options)->mapping);
    }

    /** @throws MappingNeedsConfirmation in strict mode when the mapping needs confirmation */
    public function run(Import $import, string $path, ?string $disk, ImportOptions $options): ImportResult
    {
        return $this->withLocalFile($path, $disk, function (string $local) use ($import, $options): ImportResult {
            $source = $this->resolver->resolve($import, $local, $options);

            if ($source->mapping->needsConfirmation()) {
                if ($options->strict) {
                    throw new MappingNeedsConfirmation($source->mapping);
                }

                return new ImportResult(ImportStatus::NeedsConfirmation, 0, 0, 0, 0, null, null, $source->mapping, $source->suggestedTemplate);
            }

            $result = $this->import($import, $local, $source, $options);

            if ($source->appliedTemplate !== null) {
                $this->templates->touch($source->appliedTemplate);
            }

            if ($options->templateName !== null) {
                $this->templates->save($this->template($import, $source, $options->templateName));
            }

            return $result;
        });
    }

    private function import(Import $import, string $local, ResolvedSource $source, ImportOptions $options): ImportResult
    {
        $mapping = $source->mapping;
        $failedRows = new FailedRowsWriter(
            $source->headers,
            $source->format,
            $this->sensitiveColumns($import, $mapping),
            $source->format === Format::Csv ? (new CsvSniffer)->sniff($local)->delimiter : ',',
        );
        $keys = $import->matchRules() === [] ? null : new SqliteKeyStore;
        $chunkSize = max(1, $options->chunkSize ?? config()->integer('spreadsheet.import.chunk_size', 500));
        $class = $import->model();

        try {
            $importer = new ChunkImporter(
                $import,
                new RowProcessor($import, $mapping, $this->normalizers, $this->validator),
                $failedRows,
                $keys,
                $this->matchKey,
                (new $class)->getConnection(),
            );

            $reader = Spreadsheet::read($local, ReaderOptions::fromConfig(), $source->format);

            $committed = false;

            try {
                $chunk = [];

                foreach ($reader->rows($source->sheet) as $number => $cells) {
                    if ($number <= $source->headerRow) {
                        continue;
                    }

                    $chunk[$number] = $cells;

                    if (count($chunk) >= $chunkSize) {
                        $importer->import($chunk);
                        $committed = true;
                        $chunk = [];
                    }
                }

                if ($chunk !== []) {
                    $importer->import($chunk);
                }
            } catch (LimitExceeded $e) {
                // A limit found while streaming the rows (for example ODS repeats far down the
                // sheet) stops an import whose earlier chunks are already committed.
                if ($committed && ! $e instanceof ImportLimitExceeded) {
                    throw new ImportLimitExceeded(self::limitMessage('limit_exceeded_during_import', $e), true, $e);
                }

                throw $e;
            } finally {
                $reader->close();
            }

            $disk = $this->failedRowsDisk();
            $path = $failedRows->finish($disk, config()->string('spreadsheet.failed_rows.directory', 'spreadsheet/failed-rows'));
        } catch (Throwable $e) {
            $failedRows->abort();

            throw $e;
        } finally {
            $keys?->close();
        }

        return new ImportResult(
            ImportStatus::Completed,
            $importer->created,
            $importer->updated,
            $importer->skipped,
            $importer->failed,
            $path === null ? null : $disk,
            $path,
            $mapping,
            $source->suggestedTemplate,
        );
    }

    /** @return list<int> */
    private function sensitiveColumns(Import $import, ColumnMapping $mapping): array
    {
        $sensitive = [];

        foreach ($import->fields() as $field) {
            if ($field->isSensitive()) {
                $sensitive[$field->key] = true;
            }
        }

        $columns = [];

        foreach ($mapping->columns as $match) {
            if ($match->field !== null && isset($sensitive[$match->field])) {
                $columns[] = $match->column;
            }
        }

        return $columns;
    }

    private function template(Import $import, ResolvedSource $source, string $name): MappingTemplate
    {
        $keys = MappingTemplate::mappingKeys($source->headers);
        $mapping = [];

        foreach ($source->mapping->columns as $match) {
            if ($match->field !== null && isset($keys[$match->column])) {
                $mapping[$keys[$match->column]] = $match->field;
            }
        }

        return new MappingTemplate($import->key(), $source->fingerprint, $name, $source->headers, $mapping, new DateTimeImmutable);
    }

    private function failedRowsDisk(): string
    {
        $disk = config('spreadsheet.failed_rows.disk');

        return is_string($disk) && $disk !== '' ? $disk : config()->string('filesystems.default', 'local');
    }

    /**
     * Calls back with a local path of the file: the file itself, or a temporary copy of a disk
     * file that is removed afterwards whatever happens.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function withLocalFile(string $path, ?string $disk, callable $callback): mixed
    {
        if ($disk === null) {
            return $this->explainingLimits(static fn (): mixed => $callback($path));
        }

        $local = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);

        if ($local === false) {
            throw new RuntimeException('Cannot create a temporary file for the import.');
        }

        try {
            $this->copy($disk, $path, $local);

            return $this->explainingLimits(static fn (): mixed => $callback($local));
        } finally {
            if (is_file($local)) {
                unlink($local);
            }
        }
    }

    /**
     * Turns a reader safety-limit failure into a translated message; the cell-count limit also
     * names the setting behind it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function explainingLimits(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ImportLimitExceeded $e) {
            throw $e;
        } catch (LimitExceeded $e) {
            throw new ImportLimitExceeded(self::limitMessage('limit_exceeded', $e), false, $e);
        }
    }

    private static function limitMessage(string $key, LimitExceeded $e): string
    {
        $message = (string) __('spreadsheet::messages.'.$key, ['reason' => $e->getMessage()]);
        $max = ReaderOptions::maxCells();

        // The reader's own wording of its cell-count limit (XLS cell count, ODS repeat expansion).
        $cellLimit = in_array($e->getMessage(), [
            sprintf('The workbook holds more than %d cells, which exceeds maxCells.', $max),
            sprintf('Repeated cells and rows expand to more than %d cells.', $max),
        ], true);

        return $cellLimit ? $message.' '.__('spreadsheet::messages.cell_limit_hint', ['max' => $max]) : $message;
    }

    private function copy(string $disk, string $path, string $local): void
    {
        $source = Storage::disk($disk)->readStream($path);

        if (! is_resource($source)) {
            throw new RuntimeException("Cannot read [{$path}] from the [{$disk}] disk.");
        }

        try {
            $target = fopen($local, 'wb');

            if ($target === false) {
                throw new RuntimeException('Cannot write the temporary import file.');
            }

            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw new RuntimeException("Cannot copy [{$path}] from the [{$disk}] disk.");
                }
            } finally {
                fclose($target);
            }
        } finally {
            fclose($source);
        }
    }
}
