<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

use InvalidArgumentException;

/**
 * The column-to-field mapping of one file: immutable, so every change returns a new instance.
 */
final class ColumnMapping
{
    /**
     * @param  list<ColumnMatch>  $columns
     * @param  list<string>  $requiredFields  Keys of the fields that must be mapped.
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $requiredFields = [],
        public readonly bool $confirmed = false,
    ) {}

    /** True while a human still has to look: a low-confidence match or an unmapped required field. */
    public function needsConfirmation(): bool
    {
        if ($this->confirmed) {
            return false;
        }

        foreach ($this->columns as $match) {
            if ($match->confidence === ColumnMatch::REVIEW) {
                return true;
            }
        }

        return $this->missingRequiredFields() !== [];
    }

    /** @return list<string> */
    public function missingRequiredFields(): array
    {
        $mapped = [];

        foreach ($this->columns as $match) {
            if ($match->field !== null) {
                $mapped[$match->field] = true;
            }
        }

        return array_values(array_filter($this->requiredFields, static fn (string $key): bool => ! isset($mapped[$key])));
    }

    public function confirm(): static
    {
        return new self($this->columns, $this->requiredFields, true);
    }

    /**
     * Manually maps a column to a field (or clears it with null). A field belongs to one
     * column, so any other column holding it is cleared.
     */
    public function with(int $column, ?string $field): static
    {
        if (! $this->hasColumn($column)) {
            throw new InvalidArgumentException("Unknown column {$column}.");
        }

        $columns = [];

        foreach ($this->columns as $match) {
            $columns[] = match (true) {
                $match->column === $column => new ColumnMatch(
                    $match->column,
                    $match->header,
                    $field,
                    $field === null ? 0.0 : 100.0,
                    $field === null ? ColumnMatch::NONE : ColumnMatch::AUTO,
                    $match->profile,
                ),
                $field !== null && $match->field === $field => new ColumnMatch(
                    $match->column,
                    $match->header,
                    null,
                    0.0,
                    ColumnMatch::NONE,
                    $match->profile,
                ),
                default => $match,
            };
        }

        return new self($columns, $this->requiredFields, $this->confirmed);
    }

    public function fieldFor(int $column): ?string
    {
        foreach ($this->columns as $match) {
            if ($match->column === $column) {
                return $match->field;
            }
        }

        return null;
    }

    /** @return array{columns: list<array<string, mixed>>, required_fields: list<string>, confirmed: bool} */
    public function toArray(): array
    {
        return [
            'columns' => array_map(static fn (ColumnMatch $match): array => $match->toArray(), $this->columns),
            'required_fields' => $this->requiredFields,
            'confirmed' => $this->confirmed,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): static
    {
        $columns = [];

        foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $column) {
            if (is_array($column)) {
                $columns[] = ColumnMatch::fromArray($column);
            }
        }

        $required = array_values(array_filter(
            is_array($data['required_fields'] ?? null) ? $data['required_fields'] : [],
            is_string(...),
        ));

        return new self($columns, $required, ($data['confirmed'] ?? false) === true);
    }

    private function hasColumn(int $column): bool
    {
        foreach ($this->columns as $match) {
            if ($match->column === $column) {
                return true;
            }
        }

        return false;
    }
}
