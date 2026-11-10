<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\DateNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DateNormalizerTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function unambiguousDates(): array
    {
        return [
            'dotted with trailing dot' => ['2026.09.14.'],
            'dotted' => ['2026.09.14'],
            'dmy dots' => ['14.09.2026'],
            'dmy slashes' => ['14/09/2026'],
            'mdy slashes' => ['9/14/2026'],
            'iso' => ['2026-09-14'],
            'iso with midnight time' => ['2026-09-14 00:00:00'],
            'ymd slashes' => ['2026/09/14'],
            'padded whitespace' => ['  2026-09-14  '],
        ];
    }

    #[DataProvider('unambiguousDates')]
    public function test_parses_unambiguous_dates(string $text): void
    {
        $result = $this->date($text);

        $this->assertNull($result->error);
        $this->assertInstanceOf(CarbonImmutable::class, $result->value);
        $this->assertSame('2026-09-14 00:00:00', $this->format($result, 'Y-m-d H:i:s'));
    }

    public function test_ambiguous_slash_date_is_an_error(): void
    {
        $result = $this->date('04/05/2026');

        $this->assertSame('spreadsheet::messages.ambiguous_date', $result->error);
        $this->assertNull($result->value);
    }

    public function test_equal_day_and_month_is_not_ambiguous(): void
    {
        $this->assertSame('2026-05-05', $this->format($this->date('05/05/2026'), 'Y-m-d'));
    }

    public function test_context_format_resolves_ambiguity(): void
    {
        $result = $this->date('04/05/2026', new NormalizationContext(dateFormat: 'd/m/Y'));

        $this->assertSame('2026-05-04', $this->format($result, 'Y-m-d'));

        $result = $this->date('04/05/2026', new NormalizationContext(dateFormat: 'm/d/Y'));

        $this->assertSame('2026-04-05', $this->format($result, 'Y-m-d'));
    }

    /** @return array<string, array{string}> */
    public static function invalidDates(): array
    {
        return [
            'month 13' => ['2026.13.01'],
            'april 31' => ['31/04/2026'],
            'feb 30 slashes' => ['30/02/2026'],
            'feb 30 iso' => ['2026-02-30'],
            'garbage' => ['tomorrow'],
            'two digit year' => ['14.09.26'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_do_not_roll_over(string $text): void
    {
        $result = $this->date($text);

        $this->assertSame('spreadsheet::messages.invalid_date', $result->error);
        $this->assertSame(['value' => $text], $result->errorParams);
        $this->assertNull($result->value);
    }

    public function test_context_format_rejects_rollover(): void
    {
        $result = $this->date('31/04/2026', new NormalizationContext(dateFormat: 'd/m/Y'));

        $this->assertSame('spreadsheet::messages.invalid_date', $result->error);
    }

    public function test_context_format_accepts_unpadded_parts(): void
    {
        $result = $this->date('4/5/2026', new NormalizationContext(dateFormat: 'd/m/Y'));

        $this->assertSame('2026-05-04', $this->format($result, 'Y-m-d'));
    }

    public function test_date_time_keeps_time(): void
    {
        $field = Field::make('at', FieldType::DateTime);

        $result = (new DateNormalizer)->normalize('2027.02.01. 14:30', $field, new NormalizationContext);

        $this->assertSame('2027-02-01 14:30:00', $this->format($result, 'Y-m-d H:i:s'));
    }

    public function test_date_field_drops_time_part(): void
    {
        $this->assertSame('2026-12-31 00:00:00', $this->format($this->date('2026-12-31 15:03'), 'Y-m-d H:i:s'));
    }

    public function test_slash_date_time_keeps_time(): void
    {
        $field = Field::make('at', FieldType::DateTime);

        $result = (new DateNormalizer)->normalize('14/09/2026 08:05', $field, new NormalizationContext);

        $this->assertSame('2026-09-14 08:05:00', $this->format($result, 'Y-m-d H:i:s'));
    }

    public function test_native_date_cell_passes_through(): void
    {
        $cell = new DateTimeImmutable('2026-09-14 13:45:10');

        $date = (new DateNormalizer)->normalize($cell, Field::make('d', FieldType::Date), new NormalizationContext);
        $dateTime = (new DateNormalizer)->normalize($cell, Field::make('d', FieldType::DateTime), new NormalizationContext);

        $this->assertSame('2026-09-14 00:00:00', $this->format($date, 'Y-m-d H:i:s'));
        $this->assertSame('2026-09-14 13:45:10', $this->format($dateTime, 'Y-m-d H:i:s'));
        $this->assertInstanceOf(CarbonImmutable::class, $dateTime->value);
    }

    public function test_excel_serial_number(): void
    {
        $this->assertSame('2026-09-14', $this->format($this->date(46279), 'Y-m-d'));
        $this->assertSame('2026-09-14', $this->format($this->date(46279.0), 'Y-m-d'));

        $field = Field::make('at', FieldType::DateTime);
        $result = (new DateNormalizer)->normalize(46279.5, $field, new NormalizationContext);

        $this->assertSame('2026-09-14 12:00:00', $this->format($result, 'Y-m-d H:i:s'));
    }

    public function test_out_of_range_serial_is_invalid(): void
    {
        $this->assertSame('spreadsheet::messages.invalid_date', $this->date(-5)->error);
        $this->assertSame('spreadsheet::messages.invalid_date', $this->date(99999999)->error);
    }

    public function test_blank_is_null(): void
    {
        $this->assertNull($this->date(null)->value);
        $this->assertNull($this->date('  ')->value);
        $this->assertNull($this->date('  ')->error);
    }

    private function format(NormalizedValue $result, string $format): string
    {
        $this->assertInstanceOf(CarbonImmutable::class, $result->value);

        return $result->value->format($format);
    }

    private function date(mixed $cell, ?NormalizationContext $context = null): NormalizedValue
    {
        return (new DateNormalizer)->normalize($cell, Field::make('d', FieldType::Date), $context ?? new NormalizationContext);
    }
}
