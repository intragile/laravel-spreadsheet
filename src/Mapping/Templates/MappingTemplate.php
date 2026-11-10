<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping\Templates;

use DateTimeImmutable;

/** A saved column mapping, found again by the header fingerprint or by header similarity. */
final readonly class MappingTemplate
{
    /**
     * @param  list<string>  $headers  The header row the mapping was made for.
     * @param  array<string, string>  $mapping  Normalized header => field key.
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
