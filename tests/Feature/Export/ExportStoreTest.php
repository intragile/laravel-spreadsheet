<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Export;

use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;

final class ExportStoreTest extends ExportTestCase
{
    public function test_store_writes_to_disk(): void
    {
        Storage::fake('exports');
        $before = glob(sys_get_temp_dir().'/spreadsheet-export-*') ?: [];

        $path = Export::fromRows([['name' => 'a'], ['name' => 'b']])
            ->withColumns([Column::make('Name')])
            ->store('reports/names.xlsx', 'exports');

        $this->assertSame('reports/names.xlsx', $path);
        Storage::disk('exports')->assertExists('reports/names.xlsx');
        $this->assertSame($before, glob(sys_get_temp_dir().'/spreadsheet-export-*') ?: []);

        $local = $this->tempFile('xlsx');
        file_put_contents($local, Storage::disk('exports')->get('reports/names.xlsx'));
        $this->assertSame([['Name'], ['a'], ['b']], $this->readRows($local));
    }

    public function test_store_uses_default_disk_when_none_given(): void
    {
        Storage::fake('local');

        Export::fromRows([['name' => 'a']])->withColumns([Column::make('Name')])->store('a.csv');

        Storage::disk('local')->assertExists('a.csv');
    }
}
