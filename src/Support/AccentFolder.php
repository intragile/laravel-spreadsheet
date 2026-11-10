<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Support;

/**
 * Folds accented Latin letters to their ASCII base letters.
 *
 * The table covers the Latin-1 Supplement and Latin Extended-A blocks plus the Romanian
 * comma-below letters. When ext-intl is loaded, canonical decomposition does the bulk of the
 * work and the table only supplies letters that do not decompose (for example ł, ø, ß, æ).
 * On every character of the table both paths produce identical output. Case is preserved.
 */
final class AccentFolder
{
    /** Letters that carry a decomposable diacritic. */
    private const DECOMPOSABLE = ['À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a', 'Ą' => 'A', 'ą' => 'a', 'Ć' => 'C', 'ć' => 'c', 'Ĉ' => 'C', 'ĉ' => 'c', 'Ċ' => 'C', 'ċ' => 'c', 'Č' => 'C', 'č' => 'c', 'Ď' => 'D', 'ď' => 'd', 'Ē' => 'E', 'ē' => 'e', 'Ĕ' => 'E', 'ĕ' => 'e', 'Ė' => 'E', 'ė' => 'e', 'Ę' => 'E', 'ę' => 'e', 'Ě' => 'E', 'ě' => 'e', 'Ĝ' => 'G', 'ĝ' => 'g', 'Ğ' => 'G', 'ğ' => 'g', 'Ġ' => 'G', 'ġ' => 'g', 'Ģ' => 'G', 'ģ' => 'g', 'Ĥ' => 'H', 'ĥ' => 'h', 'Ĩ' => 'I', 'ĩ' => 'i', 'Ī' => 'I', 'ī' => 'i', 'Ĭ' => 'I', 'ĭ' => 'i', 'Į' => 'I', 'į' => 'i', 'İ' => 'I', 'Ĵ' => 'J', 'ĵ' => 'j', 'Ķ' => 'K', 'ķ' => 'k', 'Ĺ' => 'L', 'ĺ' => 'l', 'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L', 'ľ' => 'l', 'Ń' => 'N', 'ń' => 'n', 'Ņ' => 'N', 'ņ' => 'n', 'Ň' => 'N', 'ň' => 'n', 'Ō' => 'O', 'ō' => 'o', 'Ŏ' => 'O', 'ŏ' => 'o', 'Ő' => 'O', 'ő' => 'o', 'Ŕ' => 'R', 'ŕ' => 'r', 'Ŗ' => 'R', 'ŗ' => 'r', 'Ř' => 'R', 'ř' => 'r', 'Ś' => 'S', 'ś' => 's', 'Ŝ' => 'S', 'ŝ' => 's', 'Ş' => 'S', 'ş' => 's', 'Š' => 'S', 'š' => 's', 'Ţ' => 'T', 'ţ' => 't', 'Ť' => 'T', 'ť' => 't', 'Ũ' => 'U', 'ũ' => 'u', 'Ū' => 'U', 'ū' => 'u', 'Ŭ' => 'U', 'ŭ' => 'u', 'Ů' => 'U', 'ů' => 'u', 'Ű' => 'U', 'ű' => 'u', 'Ų' => 'U', 'ų' => 'u', 'Ŵ' => 'W', 'ŵ' => 'w', 'Ŷ' => 'Y', 'ŷ' => 'y', 'Ÿ' => 'Y', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z', 'Ž' => 'Z', 'ž' => 'z', 'Ș' => 'S', 'ș' => 's', 'Ț' => 'T', 'ț' => 't'];

    /** Letters without a canonical decomposition. */
    private const STANDALONE = [
        'Æ' => 'AE', 'æ' => 'ae', 'Ð' => 'D', 'ð' => 'd', 'Ø' => 'O', 'ø' => 'o', 'Þ' => 'TH', 'þ' => 'th',
        'ß' => 'ss', 'Đ' => 'D', 'đ' => 'd', 'Ħ' => 'H', 'ħ' => 'h', 'ı' => 'i', 'Ĳ' => 'IJ', 'ĳ' => 'ij',
        'ĸ' => 'k', 'Ŀ' => 'L', 'ŀ' => 'l', 'Ł' => 'L', 'ł' => 'l', 'ŉ' => 'n', 'Ŋ' => 'N', 'ŋ' => 'n',
        'Œ' => 'OE', 'œ' => 'oe', 'Ŧ' => 'T', 'ŧ' => 't', 'ſ' => 's',
    ];

    public static function fold(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($value, \Normalizer::FORM_D);

            if (is_string($decomposed)) {
                return strtr((string) preg_replace('/\p{Mn}/u', '', $decomposed), self::STANDALONE);
            }
        }

        return self::foldWithTable($value);
    }

    /** Table-only folding; the fallback used when ext-intl is missing. */
    public static function foldWithTable(string $value): string
    {
        return strtr($value, self::DECOMPOSABLE + self::STANDALONE);
    }
}
