<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

/**
 * Parses dates and date-times into CarbonImmutable. Date fields return midnight.
 *
 * Calendar-invalid dates are rejected instead of rolling over, and a slash date whose day and
 * month could both be read either way is an error unless the column context names the layout.
 */
final class DateNormalizer implements Normalizer
{
    private const SLASH_DATE = '#^(\d{1,2})/(\d{1,2})/(\d{4})(?: (\d{1,2}):(\d{2})(?::(\d{2}))?)?$#';

    /** Serial number of 10000-01-01 in the 1900 date system. */
    private const MAX_SERIAL = 2958466;

    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $withTime = $field->type === FieldType::DateTime;

        if ($value instanceof DateTimeInterface) {
            return NormalizedValue::ok($this->finish(CarbonImmutable::instance($value), $withTime));
        }

        if (is_int($value) || is_float($value)) {
            $date = $this->fromSerial($value);

            return $date === null
                ? NormalizedValue::error('spreadsheet::messages.invalid_date', ['value' => Cell::text($value)])
                : NormalizedValue::ok($this->finish($date, $withTime));
        }

        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if ($context->dateFormat !== null) {
            $date = $this->parseWith([$context->dateFormat], $text);
        } elseif (preg_match(self::SLASH_DATE, $text, $m) === 1) {
            return $this->fromSlashDate($text, $m, $withTime);
        } else {
            /** @var list<string> $formats */
            $formats = array_values(array_filter((array) config('spreadsheet.import.date_formats', []), is_string(...)));
            $date = $this->parseWith($formats, $text);
        }

        return $date === null
            ? NormalizedValue::error('spreadsheet::messages.invalid_date', ['value' => $text])
            : NormalizedValue::ok($this->finish($date, $withTime));
    }

    private function finish(CarbonImmutable $date, bool $withTime): CarbonImmutable
    {
        return $withTime ? $date : $date->startOfDay();
    }

    /** @param  list<string>  $formats  Each format is also tried with a trailing time of day. */
    private function parseWith(array $formats, string $text): ?CarbonImmutable
    {
        foreach ($formats as $format) {
            foreach ([$format, $format.' H:i', $format.' H:i:s', $format.'\TH:i', $format.'\TH:i:s'] as $candidate) {
                $parsed = DateTimeImmutable::createFromFormat('!'.$candidate, $text);

                if ($parsed !== false && DateTimeImmutable::getLastErrors() === false && (int) $parsed->format('Y') >= 1000) {
                    return CarbonImmutable::instance($parsed);
                }
            }
        }

        return null;
    }

    /** @param  array<int, string>  $m */
    private function fromSlashDate(string $text, array $m, bool $withTime): NormalizedValue
    {
        [$a, $b, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];

        if ($a <= 12 && $b <= 12 && $a !== $b) {
            return NormalizedValue::error('spreadsheet::messages.ambiguous_date', ['value' => $text]);
        }

        [$day, $month] = $a > 12 ? [$a, $b] : ($b > 12 ? [$b, $a] : [$a, $b]);

        $date = checkdate($month, $day, $year) && $hour < 24 && $minute < 60 && $second < 60
            ? CarbonImmutable::create($year, $month, $day, $hour, $minute, $second)
            : null;

        return $date === null
            ? NormalizedValue::error('spreadsheet::messages.invalid_date', ['value' => $text])
            : NormalizedValue::ok($this->finish($date, $withTime));
    }

    /** Converts a spreadsheet serial number (1900 date system, with its leap-year quirk). */
    private function fromSerial(int|float $serial): ?CarbonImmutable
    {
        if (! is_finite((float) $serial) || $serial < 1 || $serial >= self::MAX_SERIAL) {
            return null;
        }

        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $epoch = $days < 61 ? '1899-12-31' : '1899-12-30';

        return CarbonImmutable::parse($epoch.' 00:00:00')->addDays($days)->addSeconds($seconds);
    }
}
