<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping\Templates;

use DateTimeImmutable;
use IntrAgile\LaravelSpreadsheet\Support\HeaderNormalizer;

/** A saved column mapping, found again by the header fingerprint or by header similarity. */
final readonly class MappingTemplate
{
    /**
     * Mapping keys for a header row, by column position: the normalized header, with `#n`
     * appended for its n-th occurrence (n >= 2) so duplicate headers stay distinct.
     * Columns whose header normalizes to nothing have no key.
     *
     * @param  list<string>  $headers
     * @return array<int, string>
     */
    public static function mappingKeys(array $headers): array
    {
        $seen = [];
        $keys = [];

        foreach ($headers as $position => $header) {
            $normalized = HeaderNormalizer::normalize($header);

            if ($normalized === '') {
                continue;
            }

            $seen[$normalized] = ($seen[$normalized] ?? 0) + 1;
            $keys[$position] = $seen[$normalized] === 1 ? $normalized : $normalized.'#'.$seen[$normalized];
        }

        return $keys;
    }

    /**
     * @param  list<string>  $headers  The header row the mapping was made for.
     * @param  array<string, string>  $mapping  Mapping key (see mappingKeys()) => field key.
     */
    public function __construct(
        public string $importKey,
        public string $fingerprint,
        public string $name,
        public array $headers,
        public array $mapping,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {}
}
