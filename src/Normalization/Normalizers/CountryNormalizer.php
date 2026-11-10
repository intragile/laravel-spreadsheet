<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization\Normalizers;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Normalization\Cell;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizer;
use Locale;

/**
 * Resolves an ISO 3166-1 alpha-2 or alpha-3 code, an English name, a configured alias or (with
 * ext-intl) a name in one of the context locales to the alpha-2 code.
 */
final class CountryNormalizer implements Normalizer
{
    /** @var array<string, array{string, string}>|null */
    private static ?array $countries = null;

    /** @var array<string, string>|null alpha-3 => alpha-2 */
    private static ?array $alpha3 = null;

    /** @var array<string, array<string, string>> locale => (folded name => alpha-2) */
    private static array $names = [];

    public function normalize(mixed $value, Field $field, NormalizationContext $context): NormalizedValue
    {
        $text = Cell::text($value);

        if ($text === null) {
            return NormalizedValue::ok(null);
        }

        $code = $this->resolve($text, $context);

        return $code === null
            ? NormalizedValue::error('spreadsheet::messages.unknown_value', ['value' => $text])
            : NormalizedValue::ok($code);
    }

    private function resolve(string $text, NormalizationContext $context): ?string
    {
        $countries = self::countries();
        $upper = mb_strtoupper($text);

        if (isset($countries[$upper])) {
            return $upper;
        }

        if (isset(self::alpha3()[$upper])) {
            return self::alpha3()[$upper];
        }

        $folded = Cell::fold($text);
        $alias = Cell::alias($folded, 'country');

        if ($alias !== null) {
            return mb_strtoupper($alias);
        }

        foreach (array_unique(['en', ...$context->locales]) as $locale) {
            $code = self::names($locale)[$folded] ?? null;

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /** @return array<string, array{string, string}> */
    private static function countries(): array
    {
        /** @var array<string, array{string, string}> */
        return self::$countries ??= require __DIR__.'/../../../resources/data/countries.php';
    }

    /** @return array<string, string> */
    private static function alpha3(): array
    {
        if (self::$alpha3 === null) {
            self::$alpha3 = [];

            foreach (self::countries() as $alpha2 => [$alpha3]) {
                self::$alpha3[$alpha3] = $alpha2;
            }
        }

        return self::$alpha3;
    }

    /** @return array<string, string> */
    private static function names(string $locale): array
    {
        if (isset(self::$names[$locale])) {
            return self::$names[$locale];
        }

        $names = [];

        if ($locale === 'en') {
            foreach (self::countries() as $alpha2 => [, $name]) {
                foreach (self::variants($name) as $variant) {
                    $names[Cell::fold($variant)] ??= $alpha2;
                }
            }
        } elseif (class_exists(Locale::class)) {
            foreach (array_keys(self::countries()) as $alpha2) {
                $name = Locale::getDisplayRegion('-'.$alpha2, $locale);

                if (is_string($name) && $name !== $alpha2) {
                    $names[Cell::fold($name)] = $alpha2;
                }
            }
        }

        return self::$names[$locale] = $names;
    }

    /**
     * A name, the name without a parenthesised qualifier, and the qualifier itself:
     * "Myanmar (Burma)" yields "Myanmar (Burma)", "Myanmar" and "Burma".
     *
     * @return list<string>
     */
    private static function variants(string $name): array
    {
        $variants = [$name];

        if (preg_match('/^(.*?)\s*\((.+)\)\s*(.*)$/u', $name, $m) === 1) {
            $variants[] = trim($m[1].' '.$m[3]);
            $variants[] = $m[2];
        }

        return $variants;
    }
}
