<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

use DateTimeInterface;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\BooleanNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\DateNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\NumberNormalizer;

/**
 * Profiles a column from its first sample values. Date, number and boolean detection reuse
 * the import normalizers, so a column is recognised exactly when its values would import.
 */
final class ValueSniffer
{
    private const MAX_SAMPLES = 50;

    private const SLASH_DATE = '#^(\d{1,2})/(\d{1,2})/\d{4}(?:\s.*)?$#';

    private readonly DateNormalizer $dates;

    private readonly NumberNormalizer $numbers;

    private readonly BooleanNormalizer $booleans;

    private readonly Field $dateField;

    private readonly Field $decimalField;

    private readonly Field $booleanField;

    public function __construct()
    {
        $this->dates = new DateNormalizer;
        $this->numbers = new NumberNormalizer;
        $this->booleans = new BooleanNormalizer;
        $this->dateField = Field::make('date', FieldType::Date);
        $this->decimalField = Field::make('decimal', FieldType::Decimal);
        $this->booleanField = Field::make('boolean', FieldType::Boolean);
    }

    /** @param  array<int|string, mixed>  $samples */
    public function profile(array $samples): ValueProfile
    {
        $values = [];

        foreach ($samples as $sample) {
            if ($this->isSample($sample)) {
                $values[] = $sample;

                if (count($values) === self::MAX_SAMPLES) {
                    break;
                }
            }
        }

        $count = count($values);

        if ($count === 0) {
            return new ValueProfile(array_fill_keys(ValueProfile::TYPES, 0.0));
        }

        $dateFormat = $this->dateFormat($values);
        $decimalSeparator = $this->decimalSeparator($values);
        $decimalContext = new NormalizationContext(decimalSeparator: $decimalSeparator);
        $dateContext = new NormalizationContext(dateFormat: $dateFormat);

        $matches = array_fill_keys(ValueProfile::TYPES, 0);

        foreach ($values as $value) {
            $text = $value instanceof DateTimeInterface ? '' : (string) Cell::text($value);

            $matches['email'] += $text !== '' && filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? 1 : 0;
            $matches['phone'] += $this->looksLikePhone($text) ? 1 : 0;
            $matches['integer'] += $this->isInteger($value, $text) ? 1 : 0;
            $matches['decimal'] += $this->isDecimal($value, $decimalContext) ? 1 : 0;
            $matches['boolean'] += $text !== '' && $this->booleans->normalize($value, $this->booleanField, new NormalizationContext)->error === null ? 1 : 0;
            $matches['date'] += $this->isDate($value, $text, $dateContext) ? 1 : 0;
        }

        return new ValueProfile(
            array_map(static fn (int $hits): float => $hits / $count, $matches),
            $count,
            $dateFormat,
            $decimalSeparator,
        );
    }

    private function isSample(mixed $value): bool
    {
        return match (true) {
            $value instanceof DateTimeInterface => true,
            is_scalar($value) => Cell::text($value) !== null,
            default => false,
        };
    }

    private function looksLikePhone(string $text): bool
    {
        if ($text === '' || preg_match('/^[\d+()\-\/\s]+$/', $text) !== 1 || preg_match(self::SLASH_DATE, $text) === 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return false;
        }

        $digits = strlen((string) preg_replace('/\D/', '', $text));

        return $digits >= 7 && $digits <= 15;
    }

    private function isInteger(mixed $value, string $text): bool
    {
        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value;
        }

        return $text !== '' && preg_match('/^[+-]?\d+$/', $text) === 1;
    }

    private function isDecimal(mixed $value, NormalizationContext $detected): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if ($value instanceof DateTimeInterface || ! is_string($value)) {
            return false;
        }

        foreach ([new NormalizationContext, $detected] as $context) {
            $result = $this->numbers->normalize($value, $this->decimalField, $context);

            if ($result->error === null && $result->value !== null) {
                return true;
            }
        }

        return false;
    }

    private function isDate(mixed $value, string $text, NormalizationContext $columnContext): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        if ($text === '' || is_int($value) || is_float($value)) {
            return false;
        }

        $context = preg_match(self::SLASH_DATE, $text) === 1 ? $columnContext : new NormalizationContext;
        $result = $this->dates->normalize($text, $this->dateField, $context);

        return $result->error === null || $result->error === 'spreadsheet::messages.ambiguous_date';
    }

    /**
     * The layout of the column's slash dates, settled by any sample whose first or second
     * part exceeds 12; null when every slash date is ambiguous or the evidence conflicts.
     *
     * @param  list<mixed>  $values
     */
    private function dateFormat(array $values): ?string
    {
        $dayFirst = false;
        $monthFirst = false;

        foreach ($values as $value) {
            if (is_string($value) && preg_match(self::SLASH_DATE, trim($value), $m) === 1) {
                $dayFirst = $dayFirst || (int) $m[1] > 12;
                $monthFirst = $monthFirst || (int) $m[2] > 12;
            }
        }

        return match (true) {
            $dayFirst && ! $monthFirst => 'd/m/Y',
            $monthFirst && ! $dayFirst => 'm/d/Y',
            default => null,
        };
    }

    /** @param  list<mixed>  $values */
    private function decimalSeparator(array $values): ?string
    {
        $votes = [',' => 0, '.' => 0];

        foreach ($values as $value) {
            if (is_string($value) && ($vote = $this->separatorVote($value)) !== null) {
                $votes[$vote]++;
            }
        }

        return match (true) {
            $votes[','] > $votes['.'] => ',',
            $votes['.'] > $votes[','] => '.',
            default => null,
        };
    }

    private function separatorVote(string $text): ?string
    {
        $compact = preg_replace('/[\s\x{00A0}\x{2009}\x{202F}\']+/u', '', $text) ?? $text;

        if (preg_match('/^[+-]?\d[\d.,]*$/', $compact) !== 1) {
            return null;
        }

        $comma = strrpos($compact, ',');
        $dot = strrpos($compact, '.');

        if ($comma !== false && $dot !== false) {
            return $comma > $dot ? ',' : '.';
        }

        $separator = $comma !== false ? ',' : ($dot !== false ? '.' : null);

        if ($separator === null || substr_count($compact, $separator) > 1) {
            return null;
        }

        $decimals = strlen($compact) - (int) strrpos($compact, $separator) - 1;

        return $decimals === 3 ? null : $separator;
    }
}
