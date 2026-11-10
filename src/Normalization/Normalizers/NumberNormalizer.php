<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

/**
 * Parses integers and decimals written with any common grouping style ("1 234,56",
 * "1,234.56", "1.234,56"). Decimals are returned as plain numeric strings so no precision
 * is lost; integers as int.
 *
 * A column decimal separator from the context settles the one genuinely ambiguous shape,
 * a single separator followed by three digits ("1.234"). Without it a dot is read as a
 * decimal point and a comma is rejected.
 */
final class NumberNormalizer implements Normalizer
{
    /** @var array<string, string>|null */
    private static ?array $currencies = null;

    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        $isInteger = $field->type === FieldType::Integer;
        $canonical = is_int($value) || is_float($value) ? self::canonical($text) : $this->parse($text, $context);

        if ($canonical === null) {
            return NormalizedValue::error($isInteger ? 'spreadsheet::messages.invalid_integer' : 'spreadsheet::messages.invalid_number', ['value' => $text]);
        }

        if (! $isInteger) {
            return NormalizedValue::ok($canonical);
        }

        $integer = str_contains($canonical, '.') ? false : filter_var($canonical, FILTER_VALIDATE_INT);

        return $integer === false
            ? NormalizedValue::error('spreadsheet::messages.invalid_integer', ['value' => $text])
            : NormalizedValue::ok($integer);
    }

    private function parse(string $text, NormalizationContext $context): ?string
    {
        $text = preg_replace('/[\x{2012}-\x{2015}\x{2212}\x{FE63}\x{FF0D}]/u', '-', $text) ?? $text;
        $text = preg_replace('/[\s\x{00A0}\x{2009}\x{202F}\x{2019}\']+/u', '', $text) ?? $text;
        $accounting = preg_match('/^\((.*)\)$/u', $text, $paren) === 1;
        $text = $accounting ? $paren[1] : $text;

        $affix = '(\p{Sc}+|\p{L}{1,3})?';

        if (preg_match('/^([+-]?)'.$affix.'([+-]?)([\d.,]+)([+-]?)'.$affix.'([+-]?)$/u', $text, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $signs = $m[1].$m[3].$m[5].$m[7];

        if (strlen($signs) > 1 || ($accounting && $signs !== '') || ! $this->isAffix($m[2]) || ! $this->isAffix($m[6])) {
            return null;
        }

        $sign = $accounting ? '-' : $signs;
        $body = (string) $m[4];

        $configured = $context->decimalSeparator;
        $decimal = $configured === ',' || $configured === '.' ? $configured : $this->inferDecimal($body);

        if ($decimal === false) {
            return null;
        }

        $parts = $decimal === null ? [$body] : explode($decimal, $body);

        if (count($parts) > 2) {
            return null;
        }

        $thousands = $decimal === ',' ? '.' : ($decimal === '.' ? ',' : (str_contains($body, ',') ? ',' : '.'));
        $integerPart = $parts[0];
        $fraction = $parts[1] ?? '';

        if (str_contains($integerPart, $thousands)) {
            if (preg_match('/^\d{1,3}(?:'.preg_quote($thousands, '/').'\d{3})+$/', $integerPart) !== 1) {
                return null;
            }

            $integerPart = str_replace($thousands, '', $integerPart);
        }

        if (preg_match('/^\d*$/', $integerPart) !== 1 || preg_match('/^\d*$/', $fraction) !== 1 || $integerPart.$fraction === '') {
            return null;
        }

        return self::canonical($sign.($integerPart === '' ? '0' : $integerPart).($fraction === '' ? '' : '.'.$fraction));
    }

    /** A currency symbol, a currency code, or a short unit token such as "Ft" or "kr". */
    private function isAffix(?string $token): bool
    {
        return $token === null || ! preg_match('/^\p{L}{3}$/u', $token) || self::isCurrencyCode($token);
    }

    private static function isCurrencyCode(string $token): bool
    {
        if (self::$currencies === null) {
            /** @var array<string, string> $loaded */
            $loaded = require __DIR__.'/../../../resources/data/currencies.php';
            self::$currencies = $loaded;
        }

        return isset(self::$currencies[mb_strtoupper($token)]);
    }

    /**
     * The decimal separator implied by the shape of the text: a separator, null when every
     * separator groups thousands, or false when the shape is ambiguous.
     *
     * @return ','|'.'|false|null
     */
    private function inferDecimal(string $body): string|false|null
    {
        $commas = substr_count($body, ',');
        $dots = substr_count($body, '.');

        if ($commas > 0 && $dots > 0) {
            return strrpos($body, ',') > strrpos($body, '.') ? ',' : '.';
        }

        if ($commas + $dots === 0) {
            return null;
        }

        if ($commas + $dots > 1) {
            return null;
        }

        $separator = $commas > 0 ? ',' : '.';

        if (strlen($body) - (int) strrpos($body, $separator) - 1 !== 3) {
            return $separator;
        }

        return $separator === '.' ? '.' : false;
    }

    /** Strips a plus sign, leading and trailing zeros and a negative zero from a plain numeric string. */
    private static function canonical(string $numeric): ?string
    {
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $numeric, $m) !== 1) {
            return null;
        }

        $integer = ltrim($m[2], '0');
        $fraction = rtrim($m[3] ?? '', '0');
        $result = ($integer === '' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $m[1] === '-' && $result !== '0' ? '-'.$result : $result;
    }
}
