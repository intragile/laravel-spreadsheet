<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use IntrAgile\LaravelSpreadsheet\Matching\DatabaseRecordMatcher;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Matching\RecordMatcher;
use LogicException;

/**
 * Declarative description of an import: target model, fields, duplicate rules and
 * how matched and new records are written.
 */
abstract class Import
{
    /**
     * Starts an import of a file with an instance resolved from the container.
     *
     * @param  string|null  $disk  Filesystem disk holding the file; null reads a local path.
     */
    public static function from(string $path, ?string $disk = null): PendingImport
    {
        $import = app(static::class);

        if (! $import instanceof static) {
            throw new LogicException('The container did not resolve '.static::class.'.');
        }

        return new PendingImport($import, $path, $disk);
    }

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** @return list<Field> */
    abstract public function fields(): array;

    public function key(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /** @return list<MatchRule> */
    public function matchRules(): array
    {
        return [];
    }

    /** The matcher that finds existing records; override to match through accessors or relations. */
    public function matcher(): RecordMatcher
    {
        return app(DatabaseRecordMatcher::class);
    }

    /**
     * A policy, or a callback `fn (Model $existing, array $values): ?array` returning the
     * attributes to write (null skips the row).
     *
     * @return MatchPolicy|Closure(Model, array<string, mixed>): ?array<string, mixed>
     */
    public function onMatch(): MatchPolicy|Closure
    {
        return MatchPolicy::Skip;
    }

    /** @return array<string, mixed> Validation rules for new records, keyed by field key. */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> Validation rules for matched records. */
    public function updateRules(): array
    {
        return $this->rules();
    }

    /**
     * Writes the canonical values onto the record. For an existing record under the
     * FillMissing policy only blank attributes are written.
     *
     * @param  array<string, mixed>  $values  Keyed by field key.
     */
    public function fill(Model $record, array $values, bool $exists): void
    {
        $onlyBlank = $exists && $this->onMatch() === MatchPolicy::FillMissing;

        foreach ($this->fields() as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $attribute = $field->attribute();

            if ($onlyBlank && ! $this->isBlank($record->getAttribute($attribute))) {
                continue;
            }

            $record->forceFill([$attribute => $values[$field->key]]);
        }
    }

    /**
     * Hook called after the record is saved, for merging child records.
     *
     * @param  array<string, mixed>  $values
     */
    public function saved(Model $record, array $values, bool $created): void {}

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
