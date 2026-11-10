<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use IntrAgile\LaravelSpreadsheet\Events\ExportCompleted;
use IntrAgile\LaravelSpreadsheet\Events\ExportFailed;
use Throwable;

/**
 * Runs a class-based export on the queue and reports the outcome through events.
 */
final class ExportJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly Export $export,
        private readonly string $path,
        private readonly ?string $disk,
    ) {}

    public function handle(): void
    {
        $rows = $this->export->storeCounted($this->path, $this->disk);

        event(new ExportCompleted($this->diskName(), $this->path, $rows, $this->export::class));
    }

    public function failed(Throwable $exception): void
    {
        event(new ExportFailed($this->diskName(), $this->path, $this->export::class, $exception->getMessage()));
    }

    private function diskName(): string
    {
        return $this->disk ?? config()->string('filesystems.default');
    }
}
