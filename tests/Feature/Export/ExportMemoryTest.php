<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Export;

use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;

final class ExportMemoryTest extends ExportTestCase
{
    public function test_memory_flat(): void
    {
        $this->seedPartners(100_000);

        $this->peakGrowth(2_000, 1_000);

        $small = $this->peakGrowth(10_000, 1_000);
        $large = $this->peakGrowth(100_000, 1_000);

        $this->assertLessThan(
            8 * 1024 * 1024,
            $large - $small,
            sprintf('Peak growth was %d bytes at 10k rows and %d bytes at 100k rows.', $small, $large),
        );
    }

    private function peakGrowth(int $rows, int $chunk): int
    {
        DB::connection()->disableQueryLog();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_peak_usage();

        $file = $this->tempFile('xlsx');

        $count = Export::fromQuery(Partner::query()->where('id', '<=', $rows))
            ->withColumns([Column::make('Name'), Column::make('City'), Column::make('Revenue')->format('#,##0.00')])
            ->sortBy('city')
            ->chunkSize($chunk)
            ->writeTo($file);

        $this->assertSame($rows, $count);
        $growth = memory_get_peak_usage() - $baseline;
        unlink($file);

        return $growth;
    }
}
