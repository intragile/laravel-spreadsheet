<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;

/**
 * Resolves an ISO 4217 code, a configured symbol, a configured alias or an English name to the
 * currency code. A code recognised from a symbol carries a warning because symbols such as "$"
 * are shared by several currencies.
 */
final class CurrencyNormalizer implements Normalizer
{
    /** @var array<string, string>|null */
    private static ?array $currencies = null;

    /** @var array<string, string>|null folded name => code */
    private static ?array $names = null;

    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        $upper = mb_strtoupper($text);

        if (isset(self::currencies()[$upper])) {
            return NormalizedValue::ok($upper);
        }

        $symbol = $this->symbol($text);

        if ($symbol !== null) {
            return NormalizedValue::ok($symbol)->withWarning('spreadsheet::messages.currency_from_symbol', ['value' => $text, 'code' => $symbol]);
        }

        $folded = Cell::fold($text);
        $code = Cell::alias($folded, 'currency');
        $code = $code !== null ? mb_strtoupper($code) : (self::names()[$folded] ?? null);

        return $code === null
            ? NormalizedValue::error('spreadsheet::messages.unknown_value', ['value' => $text])
            : NormalizedValue::ok($code);
    }

    private function symbol(string $text): ?string
    {
        $symbols = config('spreadsheet.import.currency_symbols', []);

        if (! is_array($symbols)) {
            return null;
        }

        foreach ($symbols as $symbol => $code) {
            if (is_string($code) && mb_strtolower((string) $symbol) === mb_strtolower($text)) {
                return mb_strtoupper($code);
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private static function currencies(): array
    {
        /** @var array<string, string> */
        return self::$currencies ??= require __DIR__.'/../../../resources/data/currencies.php';
    }

    /** @return array<string, string> */
    private static function names(): array
    {
        if (self::$names === null) {
            self::$names = [];

            foreach (self::currencies() as $code => $name) {
                self::$names[Cell::fold($name)] = $code;
            }
        }

        return self::$names;
    }
}
