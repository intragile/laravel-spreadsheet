<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use DateTimeImmutable;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use PHPUnit\Framework\TestCase;

final class CellTest extends TestCase
{
    public function test_date_cells_read_as_iso_text(): void
    {
        $this->assertSame('2026-01-02', Cell::text(new DateTimeImmutable('2026-01-02 00:00:00')));
        $this->assertSame('2026-01-02 08:30:00', Cell::text(new DateTimeImmutable('2026-01-02 08:30:00')));
    }

    public function test_scalars_and_blanks(): void
    {
        $this->assertNull(Cell::text(null));
        $this->assertNull(Cell::text("  \u{00A0}"));
        $this->assertSame('12', Cell::text(12));
        $this->assertSame('1', Cell::text(true));
        $this->assertNull(Cell::text([]));
    }
}
