<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use IntrAgile\Spreadsheet\Contracts\Writer;
use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Style;
use Throwable;

/**
 * Writes a header row and a stream of source rows to a local file in the format of its extension.
 */
final class ExportWriter
{
    /**
     * @param  list<Column>  $columns
     * @param  array{delimiter: string, bom: bool, escape_formulas: bool}  $csv
     */
    public function __construct(
        private readonly array $columns,
        private readonly string $sheetName,
        private readonly ?Style $headerStyle,
        private readonly array $csv,
    ) {}

    /**
     * @param  iterable<mixed>  $rows
     * @return int number of data rows written
     */
    public function write(string $localPath, iterable $rows): int
    {
        $writer = $this->open($localPath);
        $count = 0;

        try {
            $widths = [];
            foreach ($this->columns as $index => $column) {
                if ($column->getWidth() !== null) {
                    $widths[$index + 1] = $column->getWidth();
                }
            }

            $writer->sheet($this->sheetName, new SheetOptions(columnWidths: $widths, freezeRows: 1, autoFilter: true));
            $writer->addRow(array_map(static fn (Column $column): string => $column->header(), $this->columns), $this->headerStyle);

            foreach ($rows as $row) {
                $cells = [];
                foreach ($this->columns as $column) {
                    $cells[] = $column->cell($row);
                }

                $writer->addRow($cells);
                $count++;
            }

            $writer->close();
        } catch (Throwable $e) {
            if (is_file($localPath)) {
                unlink($localPath);
            }

            throw $e;
        }

        return $count;
    }

    private function open(string $localPath): Writer
    {
        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

        if ($extension === 'csv' || $extension === 'txt') {
            return new CsvWriter($localPath, $this->csv['delimiter'], $this->csv['bom'], $this->csv['escape_formulas']);
        }

        return Spreadsheet::write($localPath, $extension === 'ods' ? Format::Ods : null);
    }
}
