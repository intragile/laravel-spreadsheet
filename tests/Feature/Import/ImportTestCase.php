<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Import\ImportResult;
use IntrAgile\LaravelSpreadsheet\Tests\Support\UsesFileDatabase;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\Spreadsheet;

abstract class ImportTestCase extends TestCase
{
    use UsesFileDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $migration = require __DIR__.'/../../../database/migrations/create_spreadsheet_mapping_templates_table.php.stub';
        $this->assertTrue(is_object($migration) && method_exists($migration, 'up'));
        $migration->up();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['spreadsheet.templates.store' => 'database']);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    protected function fixture(string $name): string
    {
        return __DIR__.'/../../fixtures/'.$name;
    }

    /**
     * Writes rows (the first one is the header) to a temporary file.
     *
     * @param  list<list<mixed>>  $rows
     */
    protected function file(array $rows, string $extension = 'csv'): string
    {
        $path = $this->tempFiles[] = sys_get_temp_dir().'/import-test-'.bin2hex(random_bytes(6)).'.'.$extension;
        $writer = $extension === 'csv' ? new CsvWriter($path) : Spreadsheet::write($path);

        foreach ($rows as $row) {
            /** @var list<string|int|float|null> $row */
            $writer->addRow($row);
        }

        $writer->close();

        return $path;
    }

    /** @return list<list<mixed>> The failed-rows file, header included. */
    protected function failedRows(ImportResult $result): array
    {
        $this->assertNotNull($result->failedRowsPath);
        $contents = (string) Storage::disk($result->failedRowsDisk)->get($result->failedRowsPath);
        $path = $this->tempFiles[] = sys_get_temp_dir().'/import-failed-'.bin2hex(random_bytes(6)).'.'.pathinfo($result->failedRowsPath, PATHINFO_EXTENSION);
        file_put_contents($path, $contents);

        $rows = [];
        $reader = Spreadsheet::read($path);

        foreach ($reader->rows() as $row) {
            $rows[] = $row;
        }

        $reader->close();

        return $rows;
    }

    /** Copies a failed-rows file into a local temporary file and returns its path. */
    protected function localCopy(ImportResult $result): string
    {
        $this->assertNotNull($result->failedRowsPath);
        $path = $this->tempFiles[] = sys_get_temp_dir().'/import-copy-'.bin2hex(random_bytes(6)).'.'.pathinfo($result->failedRowsPath, PATHINFO_EXTENSION);
        file_put_contents($path, (string) Storage::disk($result->failedRowsDisk)->get($result->failedRowsPath));

        return $path;
    }
}
