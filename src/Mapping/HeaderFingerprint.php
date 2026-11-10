<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

use IntrAgile\LaravelSpreadsheet\Support\HeaderNormalizer;

/**
 * An order-independent identity of a file's header row, used to recognise a file that
 * was mapped before.
 */
final class HeaderFingerprint
{
    /**
     * @param  list<string>  $headers
     * @param  list<string>  $ignored  Headers that do not count, in raw or normalized form.
     */
    public static function of(array $headers, array $ignored): string
    {
        return hash('sha256', implode("\n", self::tokens($headers, $ignored)));
    }

    /**
     * The normalized headers the failed-rows file appends to a user's columns, in every
     * shipped locale plus the configured extras.
     *
     * @return list<string>
     */
    public static function ignoredHeaders(): array
    {
        $headers = [];

        foreach (self::locales() as $locale) {
            foreach (['status_header', 'message_header'] as $name) {
                $key = 'spreadsheet::messages.failed_rows.'.$name;
                $text = trans($key, [], $locale);

                if (is_string($text) && $text !== $key) {
                    $headers[] = HeaderNormalizer::normalize($text);
                }
            }
        }

        foreach ((array) config('spreadsheet.failed_rows.headers', []) as $extra) {
            if (is_string($extra)) {
                $headers[] = HeaderNormalizer::normalize($extra);
            }
        }

        return array_values(array_unique(array_filter($headers, static fn (string $header): bool => $header !== '')));
    }

    /**
     * The sorted normalized headers that count towards the fingerprint, duplicates kept.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $ignored
     * @return list<string>
     */
    public static function tokens(array $headers, array $ignored): array
    {
        $skip = array_flip(array_map(HeaderNormalizer::normalize(...), $ignored));
        $tokens = [];

        foreach ($headers as $header) {
            $normalized = HeaderNormalizer::normalize($header);

            if ($normalized !== '' && ! isset($skip[$normalized])) {
                $tokens[] = $normalized;
            }
        }

        sort($tokens);

        return $tokens;
    }

    /** @return list<string> */
    private static function locales(): array
    {
        $directories = glob(__DIR__.'/../../resources/lang/*', GLOB_ONLYDIR) ?: [];

        return array_map(basename(...), $directories);
    }
}
