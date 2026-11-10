<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization;

use IntrAgile\LaravelSpreadsheet\Support\AccentFolder;
use Stringable;

/** Shared reading helpers for raw cell values. */
final class Cell
{
    /** The trimmed text of a scalar cell, or null when the cell is empty. */
    public static function text(mixed $value): ?string
    {
        $text = match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::floatText($value),
            $value instanceof Stringable => (string) $value,
            default => '',
        };

        $text = preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $text) ?? $text;

        return $text === '' ? null : $text;
    }

    /** Shortest round-trip decimal text of a float, never in exponent notation. */
    public static function floatText(float $value): string
    {
        if (! is_finite($value)) {
            return is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF');
        }

        $text = var_export($value, true);

        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $text, $m, PREG_UNMATCHED_AS_NULL) === 1) {
            $digits = $m[2].($m[3] ?? '');
            $point = strlen($m[2]) + (int) $m[4];

            $text = $m[1].match (true) {
                $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
                $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)),
                default => substr($digits, 0, $point).'.'.substr($digits, $point),
            };
        }

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Lower case, accent-folded, single-spaced form used to compare names and words. */
    public static function fold(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? $value;

        return mb_strtolower(AccentFolder::fold($value));
    }

    /**
     * Looks a folded name up in an alias table from the config, whose keys are folded on the fly.
     *
     * @param  'country'|'currency'|'language'  $kind
     */
    public static function alias(string $folded, string $kind): ?string
    {
        $aliases = config('spreadsheet.import.aliases.'.$kind, []);

        if (! is_array($aliases)) {
            return null;
        }

        foreach ($aliases as $name => $target) {
            if (is_string($target) && self::fold((string) $name) === $folded) {
                return $target;
            }
        }

        return null;
    }
}
