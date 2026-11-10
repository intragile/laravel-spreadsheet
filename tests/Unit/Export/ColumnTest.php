<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Export;

use DateTimeImmutable;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Style;
use PHPUnit\Framework\TestCase;

final class ColumnTest extends TestCase
{
    public function test_string_value_is_a_data_get_path(): void
    {
        $column = Column::make('City', 'address.city');

        $this->assertSame('Pécs', $column->cell(['address' => ['city' => 'Pécs']]));
        $this->assertSame('Pécs', $column->cell((object) ['address' => (object) ['city' => 'Pécs']]));
    }

    public function test_null_value_uses_the_snake_cased_header(): void
    {
        $this->assertSame('X', Column::make('Tax Number')->cell(['tax_number' => 'X']));
        $this->assertSame('Y', Column::make('Founded-At ')->cell(['founded_at' => 'Y']));
    }

    public function test_closure_receives_the_row(): void
    {
        $column = Column::make('Label', static fn (mixed $row): string => strtoupper(is_string($name = data_get($row, 'name')) ? $name : ''));

        $this->assertSame('ACME', $column->cell(['name' => 'acme']));
    }

    public function test_format_wraps_value_and_converts_numeric_strings(): void
    {
        $cell = Column::make('Revenue')->format('#,##0.00')->cell(['revenue' => '1234.50']);

        $this->assertEquals(Cell::of(1234.5, Style::make()->numberFormat('#,##0.00')), $cell);
    }

    public function test_as_text_wraps_in_text_cell_and_keeps_null(): void
    {
        $column = Column::make('Tax', 'tax')->asText();

        $this->assertEquals(Cell::text('00123'), $column->cell(['tax' => '00123']));
        $this->assertEquals(Cell::text('42'), $column->cell(['tax' => 42]));
        $this->assertNull($column->cell(['tax' => null]));
    }

    public function test_plain_value_stays_unwrapped_and_dates_pass_through(): void
    {
        $date = new DateTimeImmutable('2026-01-02');

        $this->assertSame($date, Column::make('D', 'd')->cell(['d' => $date]));
        $this->assertSame(5, Column::make('N', 'n')->cell(['n' => 5]));
    }

    public function test_width_and_style(): void
    {
        $style = Style::make()->bold();
        $column = Column::make('A')->width(14.5)->style($style);

        $this->assertSame(14.5, $column->getWidth());
        $this->assertEquals(Cell::of('x', $style), $column->cell(['a' => 'x']));
    }
}
