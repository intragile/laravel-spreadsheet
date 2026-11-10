<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class DomainCanonicalizer implements Canonicalizer
{
    use PrefiltersBySqlExpression;

    /**
     * Reduces a URL or host to a bare lower-case ASCII domain: scheme, credentials, port,
     * path, query, fragment, trailing dot and one leading "www." are removed; IDN labels are
     * converted to punycode.
     *
     * @throws InvalidArgumentException When the input is not a registrable-looking domain.
     */
    public static function normalize(string $input): string
    {
        $host = mb_strtolower(trim($input));
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? $host;
        $host = preg_replace('#[/?\#].*$#s', '', $host) ?? $host;

        if (($at = strrpos($host, '@')) !== false) {
            $host = substr($host, $at + 1);
        }

        $host = explode(':', $host, 2)[0];
        $host = rtrim($host, '.');

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                throw new InvalidArgumentException("An IDN domain needs ext-intl: {$input}");
            }

            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii === false) {
                throw new InvalidArgumentException("Not a valid domain: {$input}");
            }

            $host = $ascii;
        }

        if (preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $host) !== 1) {
            throw new InvalidArgumentException("Not a valid domain: {$input}");
        }

        return $host;
    }

    public function canonicalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        try {
            return self::normalize((string) $value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function prefilter(EloquentBuilder|Builder $query, string $column, array $keys): void
    {
        $withWww = array_merge($keys, array_map(static fn (string $key): string => 'www.'.$key, $keys));

        $this->whereExpressionIn($query, $column, $withWww, fn (string $c): string => "lower({$c})");
    }
}
