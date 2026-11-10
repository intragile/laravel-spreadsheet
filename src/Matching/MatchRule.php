<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;
use InvalidArgumentException;

/**
 * A duplicate-detection rule: the listed fields together form one match key.
 */
final class MatchRule
{
    /** @var array<string, Canonicalizer|string> */
    private array $canonicalizers = [];

    /** @param  list<string>  $fields */
    private function __construct(
        public readonly MatchStrength $strength,
        public readonly array $fields,
    ) {
        if ($fields === []) {
            throw new InvalidArgumentException('A match rule needs at least one field.');
        }
    }

    public static function sure(string ...$fields): self
    {
        return new self(MatchStrength::Sure, array_values($fields));
    }

    public static function suspicious(string ...$fields): self
    {
        return new self(MatchStrength::Suspicious, array_values($fields));
    }

    /** Overrides the canonicalizer for one field (an instance or a registered name). */
    public function canonicalizeUsing(string $field, Canonicalizer|string $canonicalizer): self
    {
        if (! in_array($field, $this->fields, true)) {
            throw new InvalidArgumentException("Field [{$field}] is not part of this match rule.");
        }

        $this->canonicalizers[$field] = $canonicalizer;

        return $this;
    }

    public function canonicalizerFor(string $field): Canonicalizer|string|null
    {
        return $this->canonicalizers[$field] ?? null;
    }

    public function id(): string
    {
        return $this->strength->value.'|'.implode('|', $this->fields);
    }
}
