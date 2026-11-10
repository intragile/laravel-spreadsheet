<?php

declare(strict_types=1);

/*
 * Writes the sample set that is opened in real office applications (Excel, Numbers, LibreOffice)
 * before a release: a styled export in xlsx and ods, a Hungarian CSV (semicolon, BOM) and the
 * failed-rows files of an import in xlsx and ods.
 *
 * Usage: php tests/office/build-samples.php [output-dir]   (default: tests/office/out)
 */

use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\LaravelSpreadsheet\Import\FailedRows\FailedRowsWriter;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowStatus;
use IntrAgile\LaravelSpreadsheet\SpreadsheetServiceProvider;
use IntrAgile\Spreadsheet\Format;
use Orchestra\Testbench\Foundation\Application;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$dir = rtrim($argv[1] ?? $root.'/tests/office/out', '/');

if (! is_dir($dir) && ! mkdir($dir, 0777, true)) {
    fwrite(STDERR, "Cannot create output directory {$dir}\n");
    exit(1);
}

$app = Application::create(options: ['extra' => ['providers' => [SpreadsheetServiceProvider::class]]]);

$app['config']->set('spreadsheet.export.header_style', [
    'bold' => true,
    'fill' => 'D9E1F2',
]);

$rows = [
    ['id' => 1, 'name' => 'Árvíztűrő tükörfúrógép', 'amount' => 1234.56, 'date' => new DateTimeImmutable('2026-01-15'), 'code' => '00123', 'note' => 'első sor'],
    ['id' => 2, 'name' => 'Őrmester Ügyfél', 'amount' => '99.5', 'date' => new DateTimeImmutable('2026-02-28 13:45:00'), 'code' => '1e5', 'note' => null],
    ['id' => 3, 'name' => 'Győr; "idézőjeles"', 'amount' => -42, 'date' => null, 'code' => '007', 'note' => "több\nsoros"],
    ['id' => 4, 'name' => 'Szőlős', 'amount' => 1_000_000, 'date' => new DateTimeImmutable('2026-11-10'), 'code' => null, 'note' => '=1+1'],
];

$columns = [
    Column::make('ID', 'id')->width(6),
    Column::make('Név', 'name')->width(28),
    Column::make('Összeg', 'amount')->format('#,##0.00')->width(14),
    Column::make('Dátum', 'date')->format('yyyy-mm-dd')->width(12),
    Column::make('Kód', 'code')->asText()->width(10),
    Column::make('Megjegyzés', 'note')->width(20),
];

foreach (['xlsx', 'ods'] as $extension) {
    Export::fromRows($rows)->withColumns($columns)->sheet('Ügyfelek')->writeTo("{$dir}/export-styled.{$extension}");
}

$app['config']->set('spreadsheet.export.csv.delimiter', ';');
$app['config']->set('spreadsheet.export.csv.bom', true);
Export::fromRows($rows)->withColumns($columns)->writeTo("{$dir}/export-hu.csv");

$headers = ['Név', 'E-mail', 'Összeg', 'Dátum'];
$failed = [
    [['Árvíztűrő Tükör', 'nem-email', 12.5, new DateTimeImmutable('2026-03-01')], RowStatus::Error, 'Érvénytelen e-mail cím'],
    [['Őrmester Ügyfél', 'orm@example.com', 0, null], RowStatus::Skipped, 'Már létezik'],
    [['Szőlős Győző', null, 1000000, new DateTimeImmutable('2026-11-10')], RowStatus::Review, 'Ellenőrzés szükséges'],
];

foreach ([Format::Xlsx, Format::Ods] as $format) {
    $writer = new FailedRowsWriter($headers, $format, []);

    foreach ($failed as [$cells, $status, $message]) {
        $writer->write($cells, $status, $message);
    }

    $stored = $writer->finish('local', 'failed-sample');

    if ($stored === null) {
        fwrite(STDERR, "No failed-rows file for {$format->value}\n");
        exit(1);
    }

    $disk = Storage::disk('local');
    copy($disk->path($stored), "{$dir}/failed-rows.{$format->value}");
    $disk->delete($stored);
}

echo "Samples written to {$dir}\n";
