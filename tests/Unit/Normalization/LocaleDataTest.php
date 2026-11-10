<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class LocaleDataTest extends TestCase
{
    private const DATA = __DIR__.'/../../../resources/data';

    public function test_countries_are_alpha2_to_alpha3_and_english_name(): void
    {
        /** @var array<string, array{string, string}> $countries */
        $countries = require self::DATA.'/countries.php';

        $this->assertGreaterThanOrEqual(245, count($countries));
        $this->assertSame(['HUN', 'Hungary'], $countries['HU']);
        $this->assertSame(['XKX', 'Kosovo'], $countries['XK']);
        $this->assertArrayNotHasKey('ZZ', $countries);

        foreach ($countries as $alpha2 => [$alpha3, $name]) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $alpha2);
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $alpha3);
            $this->assertNotSame('', $name);
        }

        $this->assertCount(count($countries), array_unique(array_column($countries, 0)));
    }

    public function test_currencies_are_code_to_english_name(): void
    {
        /** @var array<string, string> $currencies */
        $currencies = require self::DATA.'/currencies.php';

        $this->assertGreaterThanOrEqual(150, count($currencies));
        $this->assertSame('Hungarian Forint', $currencies['HUF']);

        foreach (['EUR', 'USD', 'GBP', 'JPY', 'CHF', 'PLN', 'CZK', 'RON'] as $code) {
            $this->assertArrayHasKey($code, $currencies);
        }

        foreach (['XYZ', 'DEM', 'XXX'] as $code) {
            $this->assertArrayNotHasKey($code, $currencies);
        }

        foreach (array_keys($currencies) as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $code);
        }
    }

    public function test_languages_are_alpha2_to_alpha3_list_and_english_name(): void
    {
        /** @var array<string, array{list<string>, string}> $languages */
        $languages = require self::DATA.'/languages.php';

        $this->assertGreaterThanOrEqual(180, count($languages));
        $this->assertSame([['hun'], 'Hungarian'], $languages['hu']);
        $this->assertSame(['deu', 'ger'], $languages['de'][0]);
        $this->assertSame(['fra', 'fre'], $languages['fr'][0]);

        foreach ($languages as $alpha2 => [$alpha3s, $name]) {
            $this->assertMatchesRegularExpression('/^[a-z]{2}$/', $alpha2);
            $this->assertNotSame([], $alpha3s);
            $this->assertNotSame('', $name);

            foreach ($alpha3s as $alpha3) {
                $this->assertMatchesRegularExpression('/^[a-z]{3}$/', $alpha3);
            }
        }
    }

    public function test_message_catalogues_have_the_same_keys(): void
    {
        /** @var array<string, string> $en */
        $en = require __DIR__.'/../../../resources/lang/en/messages.php';
        /** @var array<string, string> $hu */
        $hu = require __DIR__.'/../../../resources/lang/hu/messages.php';

        $this->assertSame(array_keys($en), array_keys($hu));

        foreach (['invalid_date', 'ambiguous_date', 'invalid_number', 'invalid_integer', 'invalid_boolean', 'unknown_value', 'unknown_option', 'invalid_domain', 'currency_from_symbol'] as $key) {
            $this->assertArrayHasKey($key, $en);
        }
    }

    public function test_translations_are_registered_under_the_package_namespace(): void
    {
        $this->assertNotSame('spreadsheet::messages.invalid_date', trans('spreadsheet::messages.invalid_date', ['value' => 'x']));
        $this->assertStringContainsString('x', trans('spreadsheet::messages.invalid_date', ['value' => 'x']));
        $this->assertNotSame(
            trans('spreadsheet::messages.invalid_date', ['value' => 'x'], 'en'),
            trans('spreadsheet::messages.invalid_date', ['value' => 'x'], 'hu'),
        );
    }

    public function test_config_defaults(): void
    {
        $this->assertSame(['en'], config('spreadsheet.import.locales'));
        $this->assertContains('Y-m-d', (array) config('spreadsheet.import.date_formats'));
        $this->assertContains('igen', (array) config('spreadsheet.import.boolean.true'));
        $this->assertContains('nem', (array) config('spreadsheet.import.boolean.false'));
        $this->assertSame('HUF', config('spreadsheet.import.currency_symbols.Ft'));
        $this->assertIsArray(config('spreadsheet.import.aliases.country'));
        $this->assertIsArray(config('spreadsheet.import.aliases.currency'));
        $this->assertIsArray(config('spreadsheet.import.aliases.language'));
    }
}
