<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use IntrAgile\LaravelSpreadsheet\Events\ImportCompleted;
use IntrAgile\LaravelSpreadsheet\Events\ImportFailed;
use IntrAgile\LaravelSpreadsheet\Import\Runner\ErrorMessage;
use Throwable;

/**
 * Runs a pending import on the queue and reports the outcome through events. A mapping that
 * needs confirmation completes with that status; strict mode makes it a failure instead.
 * Database error messages are reported as a generic text unless the application runs in debug mode.
 */
final class ImportJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly PendingImport $import,
        private readonly string $importClass,
    ) {}

    public function handle(): void
    {
        event(new ImportCompleted($this->import->run(), $this->importClass));
    }

    public function failed(Throwable $exception): void
    {
        event(new ImportFailed($this->importClass, ErrorMessage::for($exception, 'database_failure')));
    }
}
