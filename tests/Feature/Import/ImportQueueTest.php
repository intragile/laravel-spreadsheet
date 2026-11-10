<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use Closure;
use Exception;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Events\ImportCompleted;
use IntrAgile\LaravelSpreadsheet\Events\ImportFailed;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Import\ImportJob;
use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;
use IntrAgile\LaravelSpreadsheet\Import\PendingImport;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Matching\RecordMatcher;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\FlakyPartnerImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\PartnerImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use RuntimeException;

final class ImportQueueTest extends ImportTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    private function upload(string $contents, string $path = 'in/file.xlsx'): string
    {
        Storage::disk('uploads')->put($path, $contents);

        return $path;
    }

    public function test_import_job_is_pushed_with_connection_and_queue(): void
    {
        Queue::fake();

        PartnerImport::from('in/partners.xlsx', 'uploads')->onConnection('redis')->onQueue('imports')->queue();

        Queue::assertPushedOn('imports', ImportJob::class, static fn (ImportJob $job): bool => $job->connection === 'redis');
    }

    public function test_queued_import_fires_completed_with_result(): void
    {
        Event::fake([ImportCompleted::class, ImportFailed::class]);
        $path = $this->upload((string) file_get_contents($this->fixture('partners.xlsx')));

        PartnerImport::from($path, 'uploads')->queue();

        Event::assertDispatched(ImportCompleted::class, static fn (ImportCompleted $e): bool => $e->importClass === PartnerImport::class
            && $e->result->status === ImportStatus::Completed
            && $e->result->created === 8
            && $e->result->failed === 2
            && $e->result->failedRowsPath !== null);
        Event::assertNotDispatched(ImportFailed::class);
        $this->assertSame(8, Partner::query()->count());
    }

    public function test_queued_import_needing_confirmation_completes_with_that_status(): void
    {
        Event::fake([ImportCompleted::class, ImportFailed::class]);
        $file = $this->file([['Name', 'Email', 'Partner city'], ['Acme', 'a@example.com', 'Pécs']]);
        $path = $this->upload((string) file_get_contents($file), 'in/review.csv');

        PartnerImport::from($path, 'uploads')->queue();

        Event::assertDispatched(ImportCompleted::class, static fn (ImportCompleted $e): bool => $e->result->status === ImportStatus::NeedsConfirmation
            && $e->result->mapping->fieldFor(2) === 'city'
            && $e->result->created === 0);
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_queued_import_failure_fires_failed_and_cleans_temp_files(): void
    {
        Event::fake([ImportCompleted::class, ImportFailed::class]);
        $before = $this->tempFiles();
        $path = $this->upload((string) file_get_contents($this->fixture('partners.xlsx')));

        try {
            FailingMatcherImport::from($path, 'uploads')->chunkSize(5)->queue();
            $this->fail('The failure should propagate on the sync queue.');
        } catch (RuntimeException $e) {
            $this->assertSame('matcher down', $e->getMessage());
        }

        Event::assertDispatched(ImportFailed::class, static fn (ImportFailed $e): bool => $e->importClass === FailingMatcherImport::class
            && $e->message === 'matcher down');
        Event::assertNotDispatched(ImportCompleted::class);
        $this->assertSame($before, $this->tempFiles());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No failed-rows file is stored for a failed import.');
    }

    public function test_inspected_and_confirmed_mapping_runs_on_the_queue(): void
    {
        Event::fake([ImportCompleted::class, ImportFailed::class]);
        $file = $this->file([['Name', 'Email', 'Partner city'], ['Acme', 'a@example.com', 'Pécs']]);
        $path = $this->upload((string) file_get_contents($file), 'in/review.csv');

        $mapping = PartnerImport::from($path, 'uploads')->inspect();
        $this->assertTrue($mapping->needsConfirmation());

        PartnerImport::from($path, 'uploads')->mapping($mapping->confirm())->queue();

        Event::assertDispatched(ImportCompleted::class, static fn (ImportCompleted $e): bool => $e->result->status === ImportStatus::Completed
            && $e->result->created === 1);
        $this->assertSame('Pécs', Partner::query()->where('name', 'Acme')->value('city'));
    }

    public function test_persistent_deadlock_fails_the_import_and_cleans_temp_files(): void
    {
        Event::fake([ImportCompleted::class, ImportFailed::class]);
        $before = $this->tempFiles();
        $path = $this->upload((string) file_get_contents($this->fixture('partners.xlsx')));
        FlakyPartnerImport::$onSaved = static function (): void {
            throw new DeadlockException('Deadlock found when trying to get lock; insert into "partners" values (\'SECRET\')');
        };

        try {
            FlakyPartnerImport::from($path, 'uploads')->queue();
            $this->fail('A deadlock on the retry must fail the import.');
        } catch (DeadlockException) {
        } finally {
            FlakyPartnerImport::$onSaved = null;
        }

        Event::assertDispatched(ImportFailed::class, static fn (ImportFailed $e): bool => $e->importClass === FlakyPartnerImport::class
            && $e->message === __('spreadsheet::messages.database_failure'));
        Event::assertNotDispatched(ImportCompleted::class);
        $this->assertSame(0, Partner::query()->count());
        $this->assertSame($before, $this->tempFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_closure_policy_built_in_on_match_runs_on_the_queue(): void
    {
        Event::fake([ImportCompleted::class, ImportFailed::class]);
        $partner = Partner::query()->create(['name' => 'Old', 'email' => 'old@example.com', 'tax_number' => 'T-1']);
        $file = $this->file([['Name', 'Email', 'City', 'Tax number'], ['New', 'new@example.com', 'Pécs', 'T-1']]);
        $path = $this->upload((string) file_get_contents($file), 'in/closure.csv');

        // The sync queue serializes the job payload and runs the unserialized copy.
        ClosurePolicyImport::from($path, 'uploads')->queue();

        Event::assertDispatched(ImportCompleted::class, static fn (ImportCompleted $e): bool => $e->result->status === ImportStatus::Completed
            && $e->result->updated === 1
            && $e->result->created === 0);
        Event::assertNotDispatched(ImportFailed::class);
        $fresh = $partner->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(['name' => 'Old', 'city' => 'PÉCS'], ['name' => $fresh->getAttribute('name'), 'city' => $fresh->getAttribute('city')]);
    }

    public function test_import_holding_a_closure_in_a_property_cannot_be_queued(): void
    {
        $file = $this->file([['Name', 'Email', 'City', 'Tax number'], ['New', 'new@example.com', 'Pécs', 'T-1']]);
        $path = $this->upload((string) file_get_contents($file), 'in/closure.csv');
        $import = new PartnerImport(static fn (Model $existing, array $values): ?array => null, [MatchRule::sure('tax_number')]);

        try {
            (new PendingImport($import, $path, 'uploads'))->queue();
            $this->fail('Serializing a closure held by the import must fail.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Closure', $e->getMessage());
        }

        $this->assertSame(0, Partner::query()->count());
    }

    /** @return list<string> */
    private function tempFiles(): array
    {
        $files = [];

        foreach (['spreadsheet-import-*', 'spreadsheet-failed-*', 'spreadsheet-keys-*'] as $pattern) {
            array_push($files, ...(glob(sys_get_temp_dir().'/'.$pattern) ?: []));
        }

        sort($files);

        return $files;
    }
}

final class FailingMatcherImport extends PartnerImport
{
    public function matchRules(): array
    {
        return [MatchRule::sure('tax_number')];
    }

    public function matcher(): RecordMatcher
    {
        // The first chunk goes through, so a failed-rows file and the key store exist when the second fails.
        return new class implements RecordMatcher
        {
            private int $calls = 0;

            public function match(Import $import, array $valuesByRow): array
            {
                if (++$this->calls > 1) {
                    throw new RuntimeException('matcher down');
                }

                return [];
            }
        };
    }
}

final class ClosurePolicyImport extends PartnerImport
{
    public function matchRules(): array
    {
        return [MatchRule::sure('tax_number')];
    }

    /** @return Closure(Model, array<string, mixed>): array<string, mixed> */
    public function onMatch(): Closure
    {
        return static fn (Model $existing, array $values): array => ['city' => mb_strtoupper(is_string($values['city'] ?? null) ? $values['city'] : '')];
    }
}
