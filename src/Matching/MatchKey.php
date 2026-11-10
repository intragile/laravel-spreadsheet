<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;
use InvalidArgumentException;

/**
 * Builds the canonical key of a record for one match rule.
 */
final class MatchKey
{
    public function __construct(private readonly CanonicalizerRegistry $registry) {}

    /** Returns the field-wise canonicalizer of a rule. */
    public function canonicalizer(Import $import, MatchRule $rule, string $fieldKey): Canonicalizer
    {
        $override = $rule->canonicalizerFor($fieldKey);

        if ($override instanceof Canonicalizer) {
            return $override;
        }

        if (is_string($override)) {
            return $this->registry->for($override);
        }

        return $this->registry->for($this->field($import, $fieldKey)->type);
    }

    public function field(Import $import, string $fieldKey): Field
    {
        foreach ($import->fields() as $field) {
            if ($field->key === $fieldKey) {
                return $field;
            }
        }

        throw new InvalidArgumentException("Match rule field [{$fieldKey}] is not a field of ".$import::class.'.');
    }

    /**
     * Resolves the canonicalizer of every field of a rule once.
     *
     * @return array<string, Canonicalizer>
     */
    public function canonicalizers(Import $import, MatchRule $rule): array
    {
        $resolved = [];

        foreach ($rule->fields as $fieldKey) {
            $resolved[$fieldKey] = $this->canonicalizer($import, $rule, $fieldKey);
        }

        return $resolved;
    }

    /**
     * Canonical parts keyed by field key, or null when any part is blank.
     *
     * @param  array<string, Canonicalizer>  $canonicalizers  As returned by canonicalizers().
     * @param  callable(string): mixed  $valueOf  Reads the raw value of a field key.
     * @return array<string, string>|null
     */
    public function parts(array $canonicalizers, callable $valueOf): ?array
    {
        $parts = [];

        foreach ($canonicalizers as $fieldKey => $canonicalizer) {
            $part = $canonicalizer->canonicalize($valueOf($fieldKey));

            if ($part === null || $part === '') {
                return null;
            }

            $parts[$fieldKey] = $part;
        }

        return $parts;
    }

    /**
     * The rule key of a values array keyed by field key, or null when any part is blank.
     *
     * @param  array<string, mixed>  $values
     */
    public function forValues(Import $import, MatchRule $rule, array $values): ?string
    {
        $parts = $this->parts($this->canonicalizers($import, $rule), static fn (string $key): mixed => $values[$key] ?? null);

        return $parts === null ? null : self::hash($rule, $parts);
    }

    /** @param  array<string, string>  $parts */
    public static function hash(MatchRule $rule, array $parts): string
    {
        return sha1($rule->id()."\x1f".implode("\x1f", $parts));
    }
}
