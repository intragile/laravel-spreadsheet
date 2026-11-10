<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Export;

use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\Support\UsesFileDatabase;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use IntrAgile\Spreadsheet\Spreadsheet;
use ZipArchive;

abstract class ExportTestCase extends TestCase
{
    use UsesFileDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    protected function tempFile(string $extension): string
    {
        return $this->tempFiles[] = sys_get_temp_dir().'/export-test-'.bin2hex(random_bytes(6)).'.'.$extension;
    }

    protected function seedPartners(int $count, bool $withNulls = false): void
    {
        $now = now()->toDateTimeString();

        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            Partner::query()->insert(array_map(static fn (int $i): array => [
                'name' => 'Partner '.$i,
                'city' => $withNulls && $i % 5 === 0 ? null : 'City '.str_pad((string) (($i * 7) % 100), 3, '0', STR_PAD_LEFT),
                'revenue' => $i * 10 + 0.5,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    /** @return list<list<mixed>> */
    protected function readRows(string $path): array
    {
        $reader = Spreadsheet::read($path);
        $rows = [];

        foreach ($reader->rows() as $row) {
            $rows[] = $row;
        }

        $reader->close();

        return $rows;
    }

    protected function zipEntry(string $path, string $entry): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $content = (string) $zip->getFromName($entry);
        $zip->close();

        return $content;
    }
}
