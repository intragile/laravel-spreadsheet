<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

use Illuminate\Contracts\Bus\Dispatcher;
use IntrAgile\LaravelSpreadsheet\Exceptions\MappingNeedsConfirmation;
use IntrAgile\LaravelSpreadsheet\Import\Runner\ImportOptions;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;

/**
 * A configured import of one file, run now with run() or on the queue with queue().
 *
 * Without an explicit mapping the columns are mapped by a saved template with the same headers
 * or by the suggester; a mapping with uncertain matches or unmapped required fields returns
 * ImportStatus::NeedsConfirmation (or throws in strict mode) before any row is read.
 */
final class PendingImport
{
    private int|string|null $sheet = null;

    private ?int $headerRow = null;

    private ?ColumnMapping $mapping = null;

    /** @var array<int, string|null>|null */
    private ?array $fieldByColumn = null;

    private bool $acceptSuggested = false;

    private bool $strict = false;

    private ?int $chunkSize = null;

    private ?string $templateName = null;

    private ?string $queueConnection = null;

    private ?string $queueName = null;

    /** @param  string|null  $disk  Filesystem disk holding the file; null reads a local path. */
    public function __construct(
        private readonly Import $import,
        private readonly string $path,
        private readonly ?string $disk = null,
    ) {}

    /** The sheet to import, by index or name; defaults to the first visible sheet with data. */
    public function sheet(int|string $sheet): self
    {
        $this->sheet = $sheet;

        return $this;
    }

    /** The real 1-based row number of the header; detected when not given. */
    public function headerRow(int $row): self
    {
        $this->headerRow = max(1, $row);

        return $this;
    }

    /**
     * Uses this mapping instead of a template or suggestion. An array maps column positions to
     * field keys; columns it leaves out are not imported.
     *
     * @param  ColumnMapping|array<int, string|null>  $mapping
     */
    public function mapping(ColumnMapping|array $mapping): self
    {
        if ($mapping instanceof ColumnMapping) {
            $this->mapping = $mapping;
            $this->fieldByColumn = null;
        } else {
            $this->mapping = null;
            $this->fieldByColumn = $mapping;
        }

        return $this;
    }

    /** Accepts the suggested mapping including its uncertain matches; unmapped required fields still stop the run. */
    public function acceptSuggestedMapping(): self
    {
        $this->acceptSuggested = true;

        return $this;
    }

    /** Throws MappingNeedsConfirmation instead of returning a NeedsConfirmation result. */
    public function strict(): self
    {
        $this->strict = true;

        return $this;
    }

    public function chunkSize(int $size): self
    {
        $this->chunkSize = max(1, $size);

        return $this;
    }

    /** Saves the mapping as a template for files with the same headers once the run completes. */
    public function saveTemplate(string $name = ''): self
    {
        $this->templateName = $name;

        return $this;
    }

    /** Sets the queue connection used by queue(). */
    public function onConnection(?string $connection): self
    {
        $this->queueConnection = $connection;

        return $this;
    }

    /** Sets the queue name used by queue(). */
    public function onQueue(?string $queue): self
    {
        $this->queueName = $queue;

        return $this;
    }

    /** The column mapping the import would use; no data row is read. */
    public function inspect(): ColumnMapping
    {
        return app(ImportRunner::class)->inspect($this->import, $this->path, $this->disk, $this->options());
    }

    /** @throws MappingNeedsConfirmation in strict mode */
    public function run(): ImportResult
    {
        return app(ImportRunner::class)->run($this->import, $this->path, $this->disk, $this->options());
    }

    /**
     * Runs the import on the queue; ImportCompleted or ImportFailed reports the outcome. The file
     * must be readable by the worker, so queued imports normally read from a shared disk.
     */
    public function queue(): mixed
    {
        $job = new ImportJob($this, $this->import::class);

        if ($this->queueConnection !== null) {
            $job->onConnection($this->queueConnection);
        }
        if ($this->queueName !== null) {
            $job->onQueue($this->queueName);
        }

        return app(Dispatcher::class)->dispatch($job);
    }

    private function options(): ImportOptions
    {
        return new ImportOptions(
            $this->sheet,
            $this->headerRow,
            $this->mapping,
            $this->fieldByColumn,
            $this->acceptSuggested,
            $this->strict,
            $this->chunkSize,
            $this->templateName,
        );
    }
}
