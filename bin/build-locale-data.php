#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates resources/data/{countries,currencies,languages}.php.
 *
 * English display names come from the ICU resource bundles of ext-intl. ICU does not expose
 * ISO 3166-1 alpha-3 or ISO 639-2 codes, so those come from the code tables that ship with
 * symfony/intl: pass the path of its Resources/data directory as the first argument or set
 * SYMFONY_INTL_DATA. The active ISO 4217 currency list is embedded below because ICU also
 * lists historic currencies.
 *
 *   php bin/build-locale-data.php /path/to/vendor/symfony/intl/Resources/data
 *
 * The generated arrays are committed and are the only runtime source; ext-intl is not needed
 * to read them.
 */
if (! extension_loaded('intl')) {
    fwrite(STDERR, "ext-intl is required to build the locale data.\n");
    exit(1);
}

$symfony = rtrim($argv[1] ?? (string) getenv('SYMFONY_INTL_DATA'), '/');

if ($symfony === '' || ! is_file($symfony.'/regions/meta.php') || ! is_file($symfony.'/languages/meta.php')) {
    fwrite(STDERR, "Pass the symfony/intl Resources/data directory as the first argument.\n");
    exit(1);
}

const ACTIVE_CURRENCIES = 'AED AFN ALL AMD ANG AOA ARS AUD AWG AZN BAM BBD BDT BGN BHD BIF BMD BND BOB BRL BSD BTN BWP BYN BZD CAD CDF CHF CLP CNY COP CRC CUP CVE CZK DJF DKK DOP DZD EGP ERN ETB EUR FJD FKP GBP GEL GHS GIP GMD GNF GTQ GYD HKD HNL HTG HUF IDR ILS INR IQD IRR ISK JMD JOD JPY KES KGS KHR KMF KPW KRW KWD KYD KZT LAK LBP LKR LRD LSL LYD MAD MDL MGA MKD MMK MNT MOP MRU MUR MVR MWK MXN MYR MZN NAD NGN NIO NOK NPR NZD OMR PAB PEN PGK PHP PKR PLN PYG QAR RON RSD RUB RWF SAR SBD SCR SDG SEK SGD SHP SLE SOS SRD SSP STN SVC SYP SZL THB TJS TMT TND TOP TRY TTD TWD TZS UAH UGX USD UYU UZS VES VND VUV WST XAF XCD XOF XPF YER ZAR ZMW ZWG';

/** ISO 639-2/B codes that differ from the terminological code, keyed by ISO 639-1. */
const BIBLIOGRAPHIC = [
    'sq' => 'alb', 'hy' => 'arm', 'eu' => 'baq', 'my' => 'bur', 'zh' => 'chi', 'cs' => 'cze', 'nl' => 'dut',
    'fr' => 'fre', 'ka' => 'geo', 'de' => 'ger', 'el' => 'gre', 'is' => 'ice', 'mk' => 'mac', 'mi' => 'mao',
    'ms' => 'may', 'fa' => 'per', 'ro' => 'rum', 'sk' => 'slo', 'bo' => 'tib', 'cy' => 'wel',
];

/** Renders a value as short-array PHP source. */
function literal(mixed $value): string
{
    if (! is_array($value)) {
        return var_export($value, true);
    }

    return '['.implode(', ', array_map(literal(...), $value)).']';
}

/** Writes a PHP array file with one entry per line. */
function export(string $path, array $data): void
{
    $lines = [];

    foreach ($data as $key => $value) {
        $lines[] = '    '.var_export($key, true).' => '.literal($value).',';
    }

    file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n".implode("\n", $lines)."\n];\n");
}

/** @return array<string, string> code => English name */
function bundle(string $package, string $key): array
{
    $names = [];

    foreach (ResourceBundle::create('en', $package)[$key] as $code => $entry) {
        $name = $entry instanceof ResourceBundle ? $entry[1] : $entry;
        $names[(string) $code] = (string) $name;
    }

    return $names;
}

$target = __DIR__.'/../resources/data';

$regions = require $symfony.'/regions/meta.php';
$countryNames = bundle('ICUDATA-region', 'Countries');
$alpha3 = $regions['Alpha2ToAlpha3'];
$alpha3['XK'] = 'XKX';
$countries = [];

foreach (array_merge($regions['Regions'], ['XK']) as $alpha2) {
    if (! isset($countryNames[$alpha2], $alpha3[$alpha2])) {
        fwrite(STDERR, "Missing data for country $alpha2\n");
        exit(1);
    }

    $countries[$alpha2] = [$alpha3[$alpha2], $countryNames[$alpha2]];
}

ksort($countries);
export($target.'/countries.php', $countries);

$currencyNames = bundle('ICUDATA-curr', 'Currencies');
$currencies = [];

foreach (explode(' ', ACTIVE_CURRENCIES) as $code) {
    if (($currencyNames[$code] ?? '') === '') {
        fwrite(STDERR, "Missing name for currency $code\n");
        exit(1);
    }

    $currencies[$code] = $currencyNames[$code];
}

export($target.'/currencies.php', $currencies);

$languageMeta = require $symfony.'/languages/meta.php';
$languageNames = bundle('ICUDATA-lang', 'Languages');
$languages = [];

foreach ($languageMeta['Alpha2ToAlpha3'] as $alpha2 => $terminological) {
    if (! isset($languageNames[$alpha2])) {
        continue;
    }

    $codes = array_values(array_unique([$terminological, ...(isset(BIBLIOGRAPHIC[$alpha2]) ? [BIBLIOGRAPHIC[$alpha2]] : [])]));
    sort($codes);
    $languages[$alpha2] = [$codes, $languageNames[$alpha2]];
}

ksort($languages);
export($target.'/languages.php', $languages);

printf("countries: %d, currencies: %d, languages: %d\n", count($countries), count($currencies), count($languages));
