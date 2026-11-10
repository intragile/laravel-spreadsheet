<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\PartnerImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;

final class HungarianCsvImportTest extends ImportTestCase
{
    public function test_hungarian_excel_csv_imports_with_decimal_comma(): void
    {
        config(['spreadsheet.import.locales' => ['hu', 'en']]);

        // Excel writes the thousands separator as a bare no-break space (0xA0 in Windows-1250).
        $this->assertStringContainsString(";1\xA0234,56;", (string) file_get_contents($this->fixture('hungarian-excel.csv')));

        $result = PartnerImport::from($this->fixture('hungarian-excel.csv'))->run();

        $this->assertSame(ImportStatus::Completed, $result->status);
        $this->assertSame(3, $result->created);
        $this->assertSame(0, $result->failed);

        $partner = Partner::query()->where('tax_number', '11111111-2-41')->sole();
        $this->assertSame('Árvíztűrő Tükörfúrógép Kft.', $partner->name);
        $this->assertSame('Győr', $partner->city);
        $this->assertSame('info@arvizturo.hu', $partner->getAttribute('email'));
        $this->assertSame('1234.56', $partner->getAttribute('revenue'));
        $this->assertSame('2026-09-14', $partner->founded_at?->format('Y-m-d'));

        $this->assertSame('987654.10', Partner::query()->where('name', 'Őszi Fűzfa Bt.')->sole()->getAttribute('revenue'));
        $this->assertSame('12.50', Partner::query()->where('city', 'Szőny')->sole()->getAttribute('revenue'));
    }
}
