<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\DomainCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\EmailCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\PhoneCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\TaxNumberCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\TextCanonicalizer;
use InvalidArgumentException;

final class CanonicalizerRegistry
{
    /** @var array<string, Canonicalizer> */
    private array $named;

    public function __construct()
    {
        $this->named = [
            'email' => new EmailCanonicalizer,
            'phone' => new PhoneCanonicalizer,
            'tax_number' => new TaxNumberCanonicalizer,
            'domain' => new DomainCanonicalizer,
            'text' => new TextCanonicalizer,
        ];
    }

    /** Resolves by field type, or by registered name. */
    public function for(FieldType|string $typeOrName): Canonicalizer
    {
        if ($typeOrName instanceof FieldType) {
            return match ($typeOrName) {
                FieldType::Email => $this->named['email'],
                FieldType::Phone => $this->named['phone'],
                FieldType::Domain => $this->named['domain'],
                default => $this->named['text'],
            };
        }

        return $this->named[$typeOrName]
            ?? throw new InvalidArgumentException("Unknown canonicalizer [{$typeOrName}].");
    }

    public function extend(string $name, Canonicalizer $canonicalizer): void
    {
        $this->named[$name] = $canonicalizer;
    }
}
