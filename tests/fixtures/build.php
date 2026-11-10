<?php

declare(strict_types=1);

/*
 * Rebuilds the import fixtures through the core writer: php tests/fixtures/build.php
 */

use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\Spreadsheet;

require __DIR__.'/../../vendor/autoload.php';

// partners.xlsx: ten partners, of which row 5 has an invalid email and row 8 has no name.
$writer = Spreadsheet::write(__DIR__.'/partners.xlsx');
$writer->sheet('Partners');
$writer->addRow(['Name', 'Email', 'Phone', 'Tax number', 'City', 'Revenue', 'Founded at']);

for ($i = 1; $i <= 10; $i++) {
    $writer->addRow([
        $i === 7 ? null : 'Partner '.$i,
        $i === 4 ? 'not-an-email' : 'partner'.$i.'@example.com',
        '+36 30 123 45'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        sprintf('1234567%d-2-41', $i % 10),
        $i % 2 === 0 ? 'Budapest' : 'Debrecen',
        1000.5 * $i,
        new DateTimeImmutable(sprintf('2020-01-%02d', $i)),
    ]);
}

$writer->close();

// hungarian-excel.csv: what Excel saves on a Hungarian system (Windows-1250, semicolons,
// decimal comma with a space as thousands separator, dotted dates).
$utf8 = tempnam(sys_get_temp_dir(), 'fixture-');
$csv = new CsvWriter($utf8, ';');
$csv->addRow(['Név', 'E-mail', 'Adószám', 'Város', 'Árbevétel', 'Alapítva']);
$csv->addRow(['Árvíztűrő Tükörfúrógép Kft.', 'info@arvizturo.hu', '11111111-2-41', 'Győr', '1 234,56', '2026.09.14.']);
$csv->addRow(['Őszi Fűzfa Bt.', 'fuzfa@example.hu', '22222222-2-42', 'Pécs', '987 654,10', '2001.02.03.']);
$csv->addRow(['Ünnepi Ételbár', 'etelbar@example.hu', '33333333-2-43', 'Szőny', '12,5', '1999.12.31.']);
$csv->close();

$bytes = iconv('UTF-8', 'Windows-1250', (string) file_get_contents($utf8));
unlink($utf8);

if ($bytes === false) {
    throw new RuntimeException('Cannot convert the Hungarian fixture to Windows-1250.');
}

file_put_contents(__DIR__.'/hungarian-excel.csv', $bytes);

// outreach.csv: a domain list with a duplicate (different spelling) and an invalid domain.
$csv = new CsvWriter(__DIR__.'/outreach.csv');
$csv->addRow(['domain', 'email', 'phone', 'name']);
$csv->addRow(['x.hu', 'info@x.hu', null, 'Kiss Anna']);
$csv->addRow(['WWW.X.hu', null, null, null]);
$csv->addRow(['foo', null, null, null]);
$csv->close();
