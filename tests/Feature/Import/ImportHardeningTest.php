<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Exceptions\ImportLimitExceeded;
use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;
use IntrAgile\LaravelSpreadsheet\Import\PendingImport;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\ConnectionBreakingImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\PartnerImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\SensitiveRevenueImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\ThrowingSavedImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use ZipArchive;

final class ImportHardeningTest extends ImportTestCase
{
    public function test_sensitive_value_never_reaches_the_failed_rows_file(): void
    {
        $path = $this->file([['Name', 'Email', 'Revenue'], ['Acme', 'a@x.hu', 'SECRET-991']]);

        foreach (['en' => 'Revenue: Not a valid number.', 'hu' => 'Árbevétel: Nem érvényes szám.'] as $locale => $message) {
            app()->setLocale($locale);
            Partner::query()->forceDelete();

            $result = (new PendingImport(new SensitiveRevenueImport, $path))->run();
            $rows = $this->failedRows($result);

            $this->assertSame(1, $result->failed);
            $this->assertStringNotContainsString('SECRET-991', (string) json_encode($rows, JSON_UNESCAPED_UNICODE));
            $this->assertSame($message, $rows[1][4]);
        }
    }

    public function test_a_throwable_in_one_row_fails_only_that_row(): void
    {
        $path = $this->file([['Name', 'Email'], ['Good', 'g@x.hu'], ['Bad', 'b@x.hu'], ['Fine', 'f@x.hu']]);

        $result = (new PendingImport(new ThrowingSavedImport, $path))->run();

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->failed);
        $this->assertSame(['Good', 'Fine'], Partner::query()->orderBy('id')->pluck('name')->all());

        $rows = $this->failedRows($result);
        $this->assertCount(2, $rows);
        $this->assertSame(['Bad', 'b@x.hu', 'Error', '"x" is not a valid backing value'], $rows[1]);
    }

    public function test_a_row_error_that_rolled_back_the_chunk_transaction_aborts_the_import(): void
    {
        ConnectionBreakingImport::$mode = 'rollback';
        $path = $this->file([['Name', 'Email'], ['Good', 'g@x.hu'], ['Bad', 'b@x.hu'], ['Fine', 'f@x.hu']]);

        $error = null;

        try {
            $result = (new PendingImport(new ConnectionBreakingImport, $path))->run();
        } catch (\RuntimeException $e) {
            $error = $e;
        }

        $this->assertNotNull($error, isset($result) ? "The import completed with {$result->created} created rows; ".Partner::query()->count().' are stored.' : '');
        $this->assertSame('The transaction was rolled back.', $error->getMessage());
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_a_lost_connection_in_a_row_aborts_the_import(): void
    {
        ConnectionBreakingImport::$mode = 'lost';
        $path = $this->file([['Name', 'Email'], ['Good', 'g@x.hu'], ['Bad', 'b@x.hu'], ['Fine', 'f@x.hu']]);

        $error = null;

        try {
            $result = (new PendingImport(new ConnectionBreakingImport, $path))->run();
        } catch (QueryException $e) {
            $error = $e;
        } finally {
            ConnectionBreakingImport::$mode = 'rollback';
        }

        $this->assertNotNull($error, isset($result) ? "The import completed with {$result->created} created rows and {$result->failed} failed." : '');
        $this->assertStringContainsString('server has gone away', $error->getMessage());
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_a_non_database_error_mentioning_a_connection_fails_only_its_row(): void
    {
        ConnectionBreakingImport::$mode = 'reset';
        $path = $this->file([['Name', 'Email'], ['Good', 'g@x.hu'], ['Bad', 'b@x.hu'], ['Fine', 'f@x.hu']]);

        try {
            $result = (new PendingImport(new ConnectionBreakingImport, $path))->run();
        } finally {
            ConnectionBreakingImport::$mode = 'rollback';
        }

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertSame(2, $result->created);
        $this->assertSame(1, $result->failed);
        $this->assertSame(['Good', 'Fine'], Partner::query()->orderBy('id')->pluck('name')->all());
    }

    public function test_overwrite_keeps_attributes_whose_cell_is_blank(): void
    {
        Partner::query()->create(['name' => 'Acme', 'email' => 'a@x.hu', 'tax_number' => '12345678-2-41', 'city' => 'Pécs']);
        $path = $this->file([['Name', 'Email', 'Tax number', 'City'], ['Acme', 'a@x.hu', '', 'Győr']]);

        $result = (new PendingImport(new PartnerImport(MatchPolicy::Overwrite, [MatchRule::sure('email')]), $path))
            ->acceptSuggestedMapping()
            ->run();

        $partner = Partner::query()->sole();
        $this->assertSame(1, $result->updated);
        $this->assertSame('12345678-2-41', $partner->tax_number);
        $this->assertSame('Győr', $partner->city);
    }

    public function test_date_cells_are_read_as_text_and_rejected_as_numbers(): void
    {
        $date = new DateTimeImmutable('2026-01-02 00:00:00');
        $path = $this->file([
            ['Name', 'City', 'Revenue'],
            ['Acme', $date, '10'],
            ['Beta', 'Pécs', $date],
        ], 'xlsx');

        $result = (new PendingImport(new PartnerImport, $path))->acceptSuggestedMapping()->run();

        $this->assertSame(1, $result->created);
        $this->assertSame('2026-01-02', Partner::query()->where('name', 'Acme')->sole()->city);

        $rows = $this->failedRows($result);
        $this->assertCount(2, $rows);
        $this->assertSame('Beta', $rows[1][0]);
        $this->assertSame('Revenue: "2026-01-02" is not a valid number.', $rows[1][4]);
    }

    public function test_failed_rows_csv_mirrors_the_upload_and_escapes_formulas(): void
    {
        config(['spreadsheet.import.locales' => ['hu', 'en']]);

        $csv = "Név;E-mail;Telefon;Város;Árbevétel\r\n"
            ."=HYPERLINK(\"http://evil\",\"x\");rossz-email;+36 1 234 5678;Győr;-12,5\r\n"
            ."Jó Kft.;jo@example.hu;;Pécs;1\r\n";
        $path = $this->file([]);
        file_put_contents($path, (string) iconv('UTF-8', 'Windows-1250', $csv));

        $result = (new PendingImport(new PartnerImport, $path))->acceptSuggestedMapping()->run();

        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->failed);

        $raw = (string) Storage::disk($result->failedRowsDisk)->get((string) $result->failedRowsPath);
        $this->assertStringStartsWith("\xEF\xBB\xBFNév;E-mail;Telefon;Város;Árbevétel;", $raw);

        $lines = preg_split('/\r?\n/', trim($raw));
        $this->assertIsArray($lines);
        $this->assertStringStartsWith('"\'=HYPERLINK(""http://evil"",""x"")";rossz-email;"+36 1 234 5678";Győr;-12,5;', $lines[1]);
    }

    public function test_cell_limit_stops_the_import_cleanly(): void
    {
        config(['spreadsheet.import.max_cells' => 5]);
        Storage::disk('local')->put('uploads/big.ods', (string) file_get_contents($this->repeatedOds()));
        $before = $this->tempLeftovers();

        try {
            PartnerImport::from('uploads/big.ods', 'local')->acceptSuggestedMapping()->run();
            $this->fail('The cell limit was not applied.');
        } catch (LimitExceeded $e) {
            $this->assertStringContainsString('spreadsheet.import.max_cells', $e->getMessage());
        }

        $this->assertSame($before, $this->tempLeftovers());
        $this->assertSame(0, Partner::query()->count());
    }

    public function test_xls_cell_limit_names_the_setting(): void
    {
        config(['spreadsheet.import.max_cells' => 10]);

        try {
            (new PendingImport(new PartnerImport, $this->fixture('grid-5x5.xls')))->acceptSuggestedMapping()->run();
            $this->fail('The XLS cell limit was not applied.');
        } catch (ImportLimitExceeded $e) {
            $this->assertFalse($e->partial);
            $this->assertSame(
                'The file exceeds a safety limit and was not imported: The workbook holds more than 10 cells, which exceeds maxCells.'
                .' The cell limit is set by spreadsheet.import.max_cells (now 10).',
                $e->getMessage(),
            );
        }

        $this->assertSame(0, Partner::query()->count());
    }

    public function test_other_limits_do_not_point_at_the_cell_limit(): void
    {
        // One row wider than the 65,536-cell row limit of ODS: a width limit, not the cell count.
        $path = $this->ods('<table:table-row>'.self::odsText('Name').'</table:table-row>'
            .'<table:table-row><table:table-cell office:value-type="string" table:number-columns-repeated="70000"><text:p>x</text:p></table:table-cell></table:table-row>');

        try {
            (new PendingImport(new PartnerImport, $path))->acceptSuggestedMapping()->run();
            $this->fail('The width limit was not applied.');
        } catch (LimitExceeded $e) {
            $this->assertStringContainsString('65536', $e->getMessage());
            $this->assertStringNotContainsString('max_cells', $e->getMessage());
        }
    }

    public function test_a_limit_reached_mid_import_says_earlier_rows_may_be_imported(): void
    {
        config(['spreadsheet.import.max_cells' => 5]);

        // Sixty plain rows pass inspection and sampling; the repeated row after them exceeds the limit.
        $rows = '<table:table-row>'.self::odsText('Name').'</table:table-row>';
        for ($i = 1; $i <= 60; $i++) {
            $rows .= '<table:table-row>'.self::odsText('Partner '.$i).'</table:table-row>';
        }
        $rows .= '<table:table-row><table:table-cell office:value-type="string" table:number-columns-repeated="10"><text:p>x</text:p></table:table-cell></table:table-row>';
        $path = $this->ods($rows);

        foreach (['en' => 'rows before that point may already have been imported', 'hu' => 'az előtte lévő sorok egy része már betöltődhetett'] as $locale => $text) {
            app()->setLocale($locale);
            Partner::query()->forceDelete();

            try {
                (new PendingImport(new PartnerImport, $path))->acceptSuggestedMapping()->chunkSize(10)->run();
                $this->fail('The cell limit was not applied.');
            } catch (LimitExceeded $e) {
                $this->assertStringContainsString($text, $e->getMessage());
                $this->assertStringContainsString('spreadsheet.import.max_cells', $e->getMessage());
                $this->assertStringNotContainsString('not imported', $e->getMessage());
            }

            $this->assertSame(60, Partner::query()->count());
        }
    }

    /** An ODS file whose data row repeats ten times over four columns. */
    private function repeatedOds(): string
    {
        return $this->ods('<table:table-row><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell></table:table-row>'
            .'<table:table-row table:number-rows-repeated="10"><table:table-cell office:value-type="string" table:number-columns-repeated="4"><text:p>Acme</text:p></table:table-cell></table:table-row>');
    }

    private function ods(string $rows): string
    {
        $path = $this->file([], 'ods');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->addFromString('content.xml', '<office:document-content'
            .' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            .' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            .' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            .'<office:body><office:spreadsheet><table:table table:name="Sheet1">'
            .$rows
            .'</table:table></office:spreadsheet></office:body></office:document-content>');
        $zip->close();

        return $path;
    }

    private static function odsText(string $text): string
    {
        return '<table:table-cell office:value-type="string"><text:p>'.$text.'</text:p></table:table-cell>';
    }

    /** @return list<string> */
    private function tempLeftovers(): array
    {
        $files = array_merge(
            glob(sys_get_temp_dir().'/spreadsheet-import-*') ?: [],
            glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: [],
        );
        sort($files);

        return $files;
    }
}
