<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;
use IntrAgile\LaravelSpreadsheet\Import\PendingImport;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
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

    /** An ODS file whose data row repeats ten times over four columns. */
    private function repeatedOds(): string
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
            .'<table:table-row><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell></table:table-row>'
            .'<table:table-row table:number-rows-repeated="10"><table:table-cell office:value-type="string" table:number-columns-repeated="4"><text:p>Acme</text:p></table:table-cell></table:table-row>'
            .'</table:table></office:spreadsheet></office:body></office:document-content>');
        $zip->close();

        return $path;
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
