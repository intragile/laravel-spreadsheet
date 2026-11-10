<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Import\PendingImport;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\PartnerImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\Spreadsheet\Csv\CsvWriter;

final class ImportMemoryTest extends ImportTestCase
{
    public function test_memory_flat(): void
    {
        DB::connection()->disableQueryLog();

        $this->peakGrowth(2_000);

        $small = $this->peakGrowth(10_000);
        $large = $this->peakGrowth(100_000);

        $this->assertLessThan(
            8 * 1024 * 1024,
            $large - $small,
            sprintf('Peak growth was %d bytes at 10k rows and %d bytes at 100k rows.', $small, $large),
        );
    }

    private function peakGrowth(int $rows): int
    {
        $file = $this->generate($rows);
        Partner::query()->forceDelete();

        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_peak_usage();

        $result = (new PendingImport(new PartnerImport(MatchPolicy::Skip, [MatchRule::sure('tax_number')]), $file))
            ->chunkSize(500)
            ->run();

        $growth = memory_get_peak_usage() - $baseline;

        $this->assertSame($rows / 2, $result->created);
        $this->assertSame($rows / 2, $result->failed);
        unlink($file);

        return $growth;
    }

    /** Half of the rows carry an invalid email. */
    private function generate(int $rows): string
    {
        $path = sys_get_temp_dir().'/import-memory-'.bin2hex(random_bytes(6)).'.csv';
        $writer = new CsvWriter($path);
        $writer->addRow(['Name', 'Email', 'Tax number', 'City', 'Revenue']);

        for ($i = 1; $i <= $rows; $i++) {
            $writer->addRow([
                'Partner '.$i,
                $i % 2 === 0 ? 'partner'.$i.'@example.com' : 'invalid-'.$i,
                'T-'.$i,
                'City '.($i % 100),
                $i * 10 + 0.5,
            ]);
        }

        $writer->close();

        return $path;
    }
}
