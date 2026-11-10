<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Support;

/**
 * Reduces a column header to a comparable token string: lower case, accents folded,
 * every run of non-alphanumeric characters collapsed to a single space.
 */
final class HeaderNormalizer
{
    public static function normalize(string $header): string
    {
        $header = str_replace(["\u{00A0}", "\t"], ' ', trim($header));
        $header = AccentFolder::fold(mb_strtolower($header));
        $header = (string) preg_replace('/[^a-z0-9]+/', ' ', $header);

        return trim($header);
    }
}
