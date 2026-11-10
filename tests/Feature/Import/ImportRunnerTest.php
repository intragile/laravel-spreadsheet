<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Exceptions\MappingNeedsConfirmation;
use IntrAgile\LaravelSpreadsheet\Import\ImportResult;
use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;
use IntrAgile\LaravelSpreadsheet\Import\PendingImport;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\PartnerImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;

final class ImportRunnerTest extends ImportTestCase
{
    /**
     * @param  MatchPolicy|Closure(Model, array<string, mixed>): ?array<string, mixed>  $policy
     * @param  list<MatchRule>  $rules
     */
    private function pending(string $path, MatchPolicy|Closure $policy = MatchPolicy::Skip, array $rules = []): PendingImport
    {
        return new PendingImport(new PartnerImport($policy, $rules), $path);
    }

    /** @param  list<list<mixed>>  $rows */
    private function reviewFile(array $rows = [['Acme', 'a@example.com', 'Pécs'], ['Beta', 'b@example.com', 'Győr']]): string
    {
        return $this->file([['Name', 'Email', 'Partner city'], ...$rows]);
    }

    private function assertCounts(ImportResult $result, int $created, int $updated, int $skipped, int $failed): void
    {
        $this->assertSame(
            ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'failed' => $failed],
            ['created' => $result->created, 'updated' => $result->updated, 'skipped' => $result->skipped, 'failed' => $result->failed],
        );
    }

    public function test_auto_mapping_imports_and_counts(): void
    {
        $result = PartnerImport::from($this->fixture('partners.xlsx'))->run();

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertCounts($result, 8, 0, 0, 2);
        $this->assertSame(8, Partner::query()->count());
        $this->assertSame('local', $result->failedRowsDisk);

        $partner = Partner::query()->where('name', 'Partner 1')->sole();
        $this->assertSame('partner1@example.com', $partner->getAttribute('email'));
        $this->assertSame('1000.50', $partner->getAttribute('revenue'));
        $this->assertSame('2020-01-01', $partner->founded_at?->format('Y-m-d'));
        $this->assertSame('12345671-2-41', $partner->getAttribute('tax_number'));

        $rows = $this->failedRows($result);
        $this->assertCount(3, $rows);
        $this->assertSame(['Name', 'Email', 'Phone', 'Tax number', 'City', 'Revenue', 'Founded at', 'Import status', 'Import message'], $rows[0]);
        $this->assertSame('Partner 4', $rows[1][0]);
        $this->assertSame('not-an-email', $rows[1][1]);
        $this->assertContains($rows[1][3], ['', null], 'Sensitive columns are blanked.');
        $this->assertSame('Error', $rows[1][7]);
        $this->assertIsString($rows[1][8]);
        $this->assertStringContainsString('Email', $rows[1][8]);
        $this->assertContains($rows[2][0], ['', null]);
        $this->assertSame('partner7@example.com', $rows[2][1]);
        $this->assertIsString($rows[2][8]);
        $this->assertStringContainsString('Name', $rows[2][8]);
    }

    public function test_review_confidence_returns_needs_confirmation_without_writing(): void
    {
        $result = $this->pending($this->reviewFile())->run();

        $this->assertSame(ImportStatus::NeedsConfirmation, $result->status);
        $this->assertCounts($result, 0, 0, 0, 0);
        $this->assertNull($result->failedRowsPath);
        $this->assertTrue($result->mapping->needsConfirmation());
        $this->assertSame('city', $result->mapping->fieldFor(2));
        $this->assertSame(0, Partner::query()->count());

        $accepted = $this->pending($this->reviewFile())->acceptSuggestedMapping()->run();

        $this->assertSame(ImportStatus::Completed, $accepted->status);
        $this->assertSame(2, $accepted->created);
        $this->assertSame('Pécs', Partner::query()->where('name', 'Acme')->value('city'));
    }

    public function test_strict_mode_throws_mapping_needs_confirmation(): void
    {
        try {
            $this->pending($this->reviewFile())->strict()->run();
            $this->fail('A mapping that needs confirmation must throw in strict mode.');
        } catch (MappingNeedsConfirmation $e) {
            $this->assertSame('city', $e->mapping->fieldFor(2));
            $this->assertTrue($e->mapping->needsConfirmation());
        }

        $this->assertSame(0, Partner::query()->count());
    }

    public function test_confirmed_mapping_runs(): void
    {
        $file = $this->reviewFile();
        $mapping = $this->pending($file)->inspect();

        $this->assertTrue($mapping->needsConfirmation());
        $this->assertSame(0, Partner::query()->count());

        $result = $this->pending($file)->mapping($mapping->confirm())->run();

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertSame(2, $result->created);
        $this->assertTrue($result->mapping->confirmed);

        $byArray = $this->pending($this->reviewFile([['Gamma', 'g@example.com', 'Szeged']]))
            ->mapping([0 => 'name', 2 => 'city'])
            ->run();

        $this->assertSame(1, $byArray->created);
        $gamma = Partner::query()->where('name', 'Gamma')->sole();
        $this->assertSame('Szeged', $gamma->getAttribute('city'));
        $this->assertNull($gamma->getAttribute('email'), 'Columns left out of an array mapping are not imported.');
    }

    public function test_missing_required_field_needs_confirmation(): void
    {
        $file = $this->file([['Email', 'City'], ['a@example.com', 'Pécs']]);

        $result = $this->pending($file)->run();

        $this->assertSame(ImportStatus::NeedsConfirmation, $result->status);
        $this->assertSame(['name'], $result->mapping->missingRequiredFields());

        $this->assertSame(ImportStatus::NeedsConfirmation, $this->pending($file)->acceptSuggestedMapping()->run()->status);
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_exact_template_applies_automatically(): void
    {
        $first = $this->reviewFile();
        $saved = $this->pending($first)->mapping($this->pending($first)->inspect()->confirm())->saveTemplate('Monthly')->run();
        $this->assertSame(ImportStatus::Completed, $saved->status);

        // Same headers in another order: the template is found by fingerprint and applied by header.
        $second = $this->file([['Partner city', 'Name', 'Email'], ['Szeged', 'Delta', 'd@example.com']]);
        $result = $this->pending($second)->run();

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertTrue($result->mapping->confirmed);
        $this->assertSame('city', $result->mapping->fieldFor(0));
        $this->assertSame(1, $result->created);
        $this->assertSame('Szeged', Partner::query()->where('name', 'Delta')->value('city'));
    }

    public function test_similar_template_only_suggested(): void
    {
        $template = $this->file([['Name', 'Email', 'Partner city', 'Phone', 'Tax number', 'Notes'], ['Acme', 'a@example.com', 'Pécs', '+36 1 234 5678', 'T-1', 'x']]);
        $saved = $this->pending($template)->mapping($this->pending($template)->inspect()->confirm())->saveTemplate('Monthly')->run();
        $this->assertSame(ImportStatus::Completed, $saved->status);

        $similar = $this->file([['Name', 'Email', 'Partner city', 'Phone', 'Tax number'], ['Beta', 'b@example.com', 'Győr', '+36 1 234 5679', 'T-2']]);
        $result = $this->pending($similar)->run();

        $this->assertSame(ImportStatus::NeedsConfirmation, $result->status);
        $this->assertFalse($result->mapping->confirmed);
        $this->assertNotNull($result->suggestedTemplate);
        $this->assertSame('Monthly', $result->suggestedTemplate->name);
        $this->assertSame(1, Partner::query()->count());
    }

    public function test_failed_rows_file_reimports_with_same_mapping(): void
    {
        $first = PartnerImport::from($this->fixture('partners.xlsx'))->saveTemplate()->run();
        $this->assertSame(2, $first->failed);

        $rows = $this->failedRows($first);
        $rows[1][1] = 'partner4@example.com';
        $rows[2][0] = 'Partner 7';
        /** @var list<list<mixed>> $rows */
        $fixed = $this->file($rows, 'xlsx');

        $result = PartnerImport::from($fixed)->run();

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertTrue($result->mapping->confirmed, 'The saved template applies to the failed-rows file.');
        $this->assertNull($result->mapping->fieldFor(7));
        $this->assertNull($result->mapping->fieldFor(8));
        $this->assertSame($first->failed, $result->created);
        $this->assertSame(0, $result->failed);
        $this->assertSame(10, Partner::query()->count());
        $this->assertSame('partner4@example.com', Partner::query()->where('name', 'Partner 4')->value('email'));
    }

    public function test_policies_skip_fill_missing_overwrite_closure(): void
    {
        $rules = [MatchRule::sure('tax_number')];
        $cases = [
            'skip' => [MatchPolicy::Skip, ['name' => 'Old', 'email' => 'old@example.com', 'city' => null], [0, 1]],
            'fill' => [MatchPolicy::FillMissing, ['name' => 'Old', 'email' => 'old@example.com', 'city' => 'Pécs'], [1, 0]],
            'overwrite' => [MatchPolicy::Overwrite, ['name' => 'New', 'email' => 'new@example.com', 'city' => 'Pécs'], [1, 0]],
            'closure' => [
                static fn (Model $existing, array $values): array => ['city' => mb_strtoupper(is_string($values['city'] ?? null) ? $values['city'] : '')],
                ['name' => 'Old', 'email' => 'old@example.com', 'city' => 'PÉCS'],
                [1, 0],
            ],
            'closure-null' => [static fn (Model $existing, array $values): ?array => null, ['name' => 'Old', 'email' => 'old@example.com', 'city' => null], [0, 1]],
        ];

        foreach ($cases as $tax => [$policy, $expected, [$updated, $skipped]]) {
            $partner = Partner::query()->create(['name' => 'Old', 'email' => 'old@example.com', 'tax_number' => $tax]);
            $file = $this->file([['Name', 'Email', 'City', 'Tax number'], ['New', 'new@example.com', 'Pécs', $tax]]);

            $result = $this->pending($file, $policy, $rules)->run();

            $this->assertCounts($result, 0, $updated, $skipped, 0);
            $this->assertNull($result->failedRowsPath, "Policy {$tax}: a skipped match is not a failed row.");
            $fresh = $partner->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame($expected, ['name' => $fresh->getAttribute('name'), 'email' => $fresh->getAttribute('email'), 'city' => $fresh->getAttribute('city')], "Policy {$tax}");
        }

        $this->assertSame(5, Partner::query()->count());
    }

    public function test_update_is_validated_with_update_rules(): void
    {
        Partner::query()->create(['name' => 'Old', 'tax_number' => 'T-1']);
        $file = $this->file([['Name', 'City', 'Tax number'], [null, 'Pécs', 'T-1'], [null, 'Győr', 'T-2']]);

        $result = $this->pending($file, MatchPolicy::FillMissing, [MatchRule::sure('tax_number')])->run();

        // The matched row passes without a name (update rules do not require it); the new one does not.
        $this->assertCounts($result, 0, 1, 0, 1);
        $this->assertSame('Pécs', Partner::query()->where('tax_number', 'T-1')->value('city'));
    }

    public function test_suspicious_match_goes_to_failed_file_as_review(): void
    {
        Partner::query()->create(['name' => 'Acme Kft.']);
        $file = $this->file([['Name', 'Email'], ['ACME KFT.', 'a@example.com'], ['Other', 'o@example.com']]);

        $result = $this->pending($file, MatchPolicy::Overwrite, [MatchRule::suspicious('name')])->run();

        $this->assertCounts($result, 1, 0, 1, 0);
        $rows = $this->failedRows($result);
        $this->assertCount(2, $rows);
        $this->assertSame('ACME KFT.', $rows[1][0]);
        $this->assertSame('Needs review', $rows[1][2]);
        $this->assertSame(2, Partner::query()->count());
    }

    public function test_soft_deleted_match_fails_and_is_not_restored(): void
    {
        $partner = Partner::query()->create(['name' => 'Gone', 'tax_number' => 'T-9']);
        $partner->delete();
        $file = $this->file([['Name', 'Tax number'], ['Back', 'T-9']]);

        $result = $this->pending($file, MatchPolicy::Overwrite, [MatchRule::sure('tax_number')])->run();

        $this->assertCounts($result, 0, 0, 0, 1);
        $rows = $this->failedRows($result);
        $this->assertSame('Error', $rows[1][2]);
        $this->assertSame(__('spreadsheet::messages.matches_trashed'), $rows[1][3]);

        $fresh = Partner::withTrashed()->findOrFail($partner->id);
        $this->assertTrue($fresh->trashed());
        $this->assertSame('Gone', $fresh->name);
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_in_file_duplicates_are_skipped_across_chunks(): void
    {
        $file = $this->file([['Name', 'Tax number'], ['A', 'T-1'], ['B', 'T-2'], ['C', 'T-1'], ['D', 'T-2']]);

        $result = $this->pending($file, MatchPolicy::Overwrite, [MatchRule::sure('tax_number')])->chunkSize(2)->run();

        $this->assertCounts($result, 2, 0, 2, 0);
        $rows = $this->failedRows($result);
        $this->assertSame(['C', 'Skipped', __('spreadsheet::messages.duplicate_in_file', ['row' => 2])], [$rows[1][0], $rows[1][2], $rows[1][3]]);
        $this->assertSame(__('spreadsheet::messages.duplicate_in_file', ['row' => 3]), $rows[2][3]);
        $this->assertSame(['A', 'B'], Partner::query()->orderBy('id')->pluck('name')->all());
    }

    public function test_db_exception_fails_only_that_row(): void
    {
        Schema::table('partners', static function (Blueprint $table): void {
            $table->unique('tax_number');
        });
        $file = $this->file([['Name', 'Tax number'], ['A', 'T-1'], ['B', 'T-2'], ['C', 'T-1'], ['D', 'T-3']]);

        $result = $this->pending($file)->run();

        $this->assertCounts($result, 3, 0, 0, 1);
        $this->assertSame(['A', 'B', 'D'], Partner::query()->orderBy('id')->pluck('name')->all());
        $rows = $this->failedRows($result);
        $this->assertSame('C', $rows[1][0]);
        $this->assertSame('Error', $rows[1][2]);
        $this->assertSame(__('spreadsheet::messages.database_error'), $rows[1][3]);

        config(['app.debug' => true]);
        $debug = $this->pending($this->file([['Name', 'Tax number'], ['E', 'T-1']]))->run();
        $message = $this->failedRows($debug)[1][3];
        $this->assertIsString($message);
        $this->assertStringContainsString('UNIQUE', $message);
    }

    public function test_disk_file_is_copied_and_temp_files_are_removed(): void
    {
        $before = $this->tempFiles();
        Storage::fake('uploads');
        Storage::disk('uploads')->put('in/partners.xlsx', (string) file_get_contents($this->fixture('partners.xlsx')));

        $result = PartnerImport::from('in/partners.xlsx', 'uploads')->run();

        $this->assertSame(8, $result->created);
        $this->assertSame($before, $this->tempFiles());
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
