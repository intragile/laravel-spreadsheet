<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Import\FailedRows\FailedRowsWriter;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowStatus;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\Spreadsheet;
use ZipArchive;

final class FailedRowsWriterTest extends TestCase
{
    /** @return list<list<mixed>> */
    private function readBack(string $contents, string $extension): array
    {
        $path = tempnam(sys_get_temp_dir(), 'failed-read-').'.'.$extension;
        file_put_contents($path, $contents);

        try {
            $rows = [];
            foreach (Spreadsheet::read($path)->rows() as $row) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            unlink($path);
        }
    }

    public function test_header_row_has_an_autofilter(): void
    {
        Storage::fake('local');

        foreach ([[Format::Xlsx, 'xl/worksheets/sheet1.xml', '/<autoFilter ref="A1:C\d+"/'], [Format::Ods, 'content.xml', '/display-filter-buttons="true"/']] as [$format, $entry, $pattern]) {
            $writer = new FailedRowsWriter(['Code'], $format, []);
            $writer->write(['x'], RowStatus::Error, 'Bad');
            $path = $writer->finish('local', 'spreadsheet/failed-rows');
            $this->assertNotNull($path);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open(Storage::disk('local')->path($path)) === true);
            $this->assertMatchesRegularExpression($pattern, (string) $zip->getFromName($entry));
            $zip->close();
        }
    }

    public function test_writes_headers_status_and_message_and_preserves_types(): void
    {
        Storage::fake('local');
        $date = new DateTimeImmutable('2026-03-04 00:00:00');

        $writer = new FailedRowsWriter(['Code', 'Joined', 'Secret'], Format::Xlsx, [2]);
        $writer->write(['00123', $date, 'hunter2'], RowStatus::Error, 'Bad code');
        $writer->write(['=1+1', null, 'x'], RowStatus::Skipped, 'Duplicate');
        $this->assertSame(2, $writer->count());

        $path = $writer->finish('local', 'spreadsheet/failed-rows');

        $this->assertNotNull($path);
        $this->assertStringStartsWith('spreadsheet/failed-rows/', $path);
        $this->assertStringEndsWith('.xlsx', $path);
        Storage::disk('local')->assertExists($path);

        $rows = $this->readBack((string) Storage::disk('local')->get($path), 'xlsx');

        $this->assertSame(['Code', 'Joined', 'Secret', 'Import status', 'Import message'], array_slice($rows[0], 0, 5));
        $this->assertSame('00123', $rows[1][0]);
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][1]);
        $this->assertContains($rows[1][2], ['', null]);
        $this->assertSame('Error', $rows[1][3]);
        $this->assertSame('Bad code', $rows[1][4]);
        $this->assertSame('=1+1', $rows[2][0]);
    }

    public function test_status_text_is_translated(): void
    {
        Storage::fake('local');
        app()->setLocale('hu');

        $writer = new FailedRowsWriter(['A'], Format::Csv, []);
        $writer->write(['x'], RowStatus::Review, 'm');
        $path = $writer->finish('local', 'out');

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.csv', $path);
        $csv = Storage::disk('local')->get($path);
        $this->assertStringContainsString('Import státusz', (string) $csv);
        $this->assertStringContainsString('Felülvizsgálat', (string) $csv);
    }

    public function test_xls_input_is_written_as_xlsx(): void
    {
        Storage::fake('local');

        $writer = new FailedRowsWriter(['A'], Format::Xls, []);
        $writer->write(['x'], RowStatus::Error, 'm');
        $path = $writer->finish(null, 'out');

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.xlsx', $path);
    }

    public function test_nothing_written_returns_null_and_leaves_no_file(): void
    {
        Storage::fake('local');
        $before = glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: [];

        $writer = new FailedRowsWriter(['A'], Format::Xlsx, []);

        $this->assertSame(0, $writer->count());
        $this->assertNull($writer->finish('local', 'out'));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($before, glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: []);
    }

    public function test_abort_removes_the_temp_file(): void
    {
        $before = glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: [];

        $writer = new FailedRowsWriter(['A'], Format::Csv, []);
        $writer->write(['x'], RowStatus::Error, 'm');
        $writer->abort();

        $this->assertSame($before, glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: []);
    }

    public function test_failed_store_throws_and_removes_the_temp_file(): void
    {
        $before = glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: [];
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        $writer = new FailedRowsWriter(['A'], Format::Csv, []);
        $writer->write(['x'], RowStatus::Error, 'm');

        try {
            $writer->finish(null, 'out');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException) {
            $this->assertSame($before, glob(sys_get_temp_dir().'/spreadsheet-failed-*') ?: []);
        }
    }

    public function test_memory_flat(): void
    {
        Storage::fake('local');

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
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_peak_usage();

        $writer = new FailedRowsWriter(['Code', 'Name', 'Amount'], Format::Xlsx, [1]);

        for ($i = 0; $i < $rows; $i++) {
            $writer->write(['C'.$i, 'Name '.$i, $i * 1.5], RowStatus::Error, 'Invalid amount');
        }

        $this->assertNotNull($writer->finish('local', 'out'));
        Storage::disk('local')->deleteDirectory('out');

        return memory_get_peak_usage() - $baseline;
    }
}
