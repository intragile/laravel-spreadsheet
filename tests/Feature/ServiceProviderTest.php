<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use IntrAgile\LaravelSpreadsheet\SpreadsheetServiceProvider;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_provider_merges_config_and_publishes_it(): void
    {
        $this->assertSame(1000, config('spreadsheet.export.chunk_size'));
        $this->assertSame(',', config('spreadsheet.export.csv.delimiter'));
        $this->assertFalse(config('spreadsheet.export.csv.bom'));
        $this->assertTrue(config('spreadsheet.export.csv.escape_formulas'));
        $this->assertIsArray(config('spreadsheet.import'));
        $this->assertNull(config('spreadsheet.templates.store'));

        $paths = ServiceProvider::pathsToPublish(SpreadsheetServiceProvider::class, 'spreadsheet-config');
        $this->assertContains(config_path('spreadsheet.php'), array_values($paths));
    }
}
