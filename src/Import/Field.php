<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

/**
 * Describes one importable field: its key in the canonical row, its type, the labels and
 * synonyms used to match file headers, and the model attribute it is written to.
 *
 * A plain string label is stored under the wildcard locale '*'.
 */
final class Field
{
    public const ANY_LOCALE = '*';

    /** @var array<string, string> */
    private array $labels = [];

    /** @var list<string> */
    private array $synonyms = [];

    private bool $required = false;

    private bool $sensitive = false;

    private ?string $attribute = null;

    /** @var array<string, array<string, string>> */
    private array $options = [];

    private function __construct(
        public readonly string $key,
        public readonly FieldType $type,
    ) {}

    public static function make(string $key, FieldType $type = FieldType::Text): self
    {
        return new self($key, $type);
    }

    /** @param  string|array<string, string>  $labels  A label, or a locale => label map. */
    public function label(string|array $labels): self
    {
        $this->labels = is_string($labels) ? [self::ANY_LOCALE => $labels] : $labels;

        return $this;
    }

    public function synonyms(string ...$synonyms): self
    {
        $this->synonyms = array_values($synonyms);

        return $this;
    }

    public function required(): self
    {
        $this->required = true;

        return $this;
    }

    /** @param  array<string, string|array<string, string>>  $valueToLabels  Labels are a string or a locale => label map. */
    public function options(array $valueToLabels): self
    {
        $this->options = [];

        foreach ($valueToLabels as $value => $labels) {
            $this->options[(string) $value] = is_string($labels) ? [self::ANY_LOCALE => $labels] : $labels;
        }

        return $this;
    }

    public function column(string $attribute): self
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function sensitive(): self
    {
        $this->sensitive = true;

        return $this;
    }

    public function attribute(): string
    {
        return $this->attribute ?? $this->key;
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return list<string> */
    public function synonymList(): array
    {
        return $this->synonyms;
    }

    /** @return array<string, array<string, string>> value => (locale => label) */
    public function optionMap(): array
    {
        return $this->options;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isSensitive(): bool
    {
        return $this->sensitive;
    }
}
