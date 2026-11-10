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
 * Resolves an ISO 639-1 or ISO 639-2 code, an English name, a configured alias or (with
 * ext-intl) a name in one of the context locales to the ISO 639-1 code.
 */
final class LanguageNormalizer implements Normalizer
{
    /** @var array<string, array{list<string>, string}>|null */
    private static ?array $languages = null;

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
        $lower = mb_strtolower($text);

        if (isset(self::languages()[$lower])) {
            return $lower;
        }

        if (isset(self::alpha3()[$lower])) {
            return self::alpha3()[$lower];
        }

        $folded = Cell::fold($text);
        $alias = Cell::alias($folded, 'language');

        if ($alias !== null) {
            return mb_strtolower($alias);
        }

        foreach (array_unique(['en', ...$context->locales]) as $locale) {
            $code = self::names($locale)[$folded] ?? null;

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /** @return array<string, array{list<string>, string}> */
    private static function languages(): array
    {
        /** @var array<string, array{list<string>, string}> */
        return self::$languages ??= require __DIR__.'/../../../resources/data/languages.php';
    }

    /** @return array<string, string> */
    private static function alpha3(): array
    {
        if (self::$alpha3 === null) {
            self::$alpha3 = [];

            foreach (self::languages() as $alpha2 => [$codes]) {
                foreach ($codes as $code) {
                    self::$alpha3[$code] = $alpha2;
                }
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
            foreach (self::languages() as $alpha2 => [, $name]) {
                $names[Cell::fold($name)] = $alpha2;
            }
        } elseif (class_exists(Locale::class)) {
            foreach (array_keys(self::languages()) as $alpha2) {
                $name = Locale::getDisplayLanguage($alpha2, $locale);

                if (is_string($name) && $name !== $alpha2) {
                    $names[Cell::fold($name)] = $alpha2;
                }
            }
        }

        return self::$names[$locale] = $names;
    }
}
