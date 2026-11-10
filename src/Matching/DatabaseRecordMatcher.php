<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;

/**
 * Matches rows against the database with one prefiltered query per rule and row batch.
 * Candidates are re-canonicalized in PHP and compared exactly.
 */
final class DatabaseRecordMatcher implements RecordMatcher
{
    /** Keeps one query's bindings well below SQL Server's limit of 2100. */
    private const MAX_BINDINGS = 1800;

    public function __construct(private readonly MatchKey $keys) {}

    public function match(Import $import, array $valuesByRow): array
    {
        /** @var array<int, MatchResult> $results */
        $results = [];
        $modelClass = $import->model();

        foreach ($import->matchRules() as $rule) {
            $canonicalizers = $this->keys->canonicalizers($import, $rule);
            $attributes = [];

            foreach ($rule->fields as $fieldKey) {
                $attributes[$fieldKey] = $this->keys->field($import, $fieldKey)->attribute();
            }

            $pending = $this->keyedRows($rule, $canonicalizers, $valuesByRow, $results);

            if ($pending === []) {
                continue;
            }

            $batchSize = $this->batchSize(count($rule->fields));

            foreach (array_chunk($pending, $batchSize, true) as $batch) {
                $found = $this->lookup($rule, $canonicalizers, $attributes, $modelClass, $batch);

                foreach ($batch as $row => $entry) {
                    if (isset($found[$entry['key']])) {
                        $record = $found[$entry['key']];
                        $results[$row] = new MatchResult($rule->strength, $rule->id(), $record, $this->isTrashed($record));
                    }
                }
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Rows still unmatched that yield a key for the rule.
     *
     * @param  array<string, Canonicalizer>  $canonicalizers
     * @param  array<int, array<string, mixed>>  $valuesByRow
     * @param  array<int, MatchResult>  $results
     * @return array<int, array{key: string, parts: array<string, string>, raw: array<string, string>}>
     */
    private function keyedRows(MatchRule $rule, array $canonicalizers, array $valuesByRow, array $results): array
    {
        $pending = [];

        foreach ($valuesByRow as $row => $values) {
            if (isset($results[$row])) {
                continue;
            }

            $parts = $this->keys->parts($canonicalizers, static fn (string $key): mixed => $values[$key] ?? null);

            if ($parts !== null) {
                $raw = [];

                foreach ($rule->fields as $fieldKey) {
                    $value = $values[$fieldKey] ?? null;
                    $raw[$fieldKey] = is_scalar($value) ? (string) $value : '';
                }

                $pending[$row] = ['key' => MatchKey::hash($rule, $parts), 'parts' => $parts, 'raw' => $raw];
            }
        }

        return $pending;
    }

    /** @return int<1, max> */
    private function batchSize(int $fieldCount): int
    {
        $configured = max(1, (int) (is_numeric($setting = config('spreadsheet.import.lookup_batch', 500)) ? $setting : 500));

        // Per field a row can contribute a key, its raw value and (domains) a www. variant.
        return max(1, min($configured, intdiv(self::MAX_BINDINGS, max(1, $fieldCount) * 3)));
    }

    /**
     * @param  array<string, Canonicalizer>  $canonicalizers
     * @param  array<string, string>  $attributes  Field key => model attribute.
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array{key: string, parts: array<string, string>, raw: array<string, string>}>  $batch
     * @return array<string, Model> Rule key => record.
     */
    private function lookup(MatchRule $rule, array $canonicalizers, array $attributes, string $modelClass, array $batch): array
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        foreach ($rule->fields as $fieldKey) {

            $canonical = [];
            $raw = [];

            foreach ($batch as $entry) {
                $canonical[] = $entry['parts'][$fieldKey];
                $raw[] = $entry['raw'][$fieldKey];
            }

            $canonicalizers[$fieldKey]->prefilter(
                $query,
                $attributes[$fieldKey],
                array_values(array_unique($canonical)),
                array_values(array_unique($raw)),
            );
        }

        $wanted = array_flip(array_column($batch, 'key'));
        $found = [];

        $query->orderBy($query->getModel()->getQualifiedKeyName());

        foreach ($query->cursor() as $candidate) {
            $parts = $this->keys->parts($canonicalizers, static fn (string $key): mixed => $candidate->getAttribute($attributes[$key]));

            if ($parts === null) {
                continue;
            }

            $key = MatchKey::hash($rule, $parts);

            if (! isset($wanted[$key])) {
                continue;
            }

            if (! isset($found[$key]) || ($this->isTrashed($found[$key]) && ! $this->isTrashed($candidate))) {
                $found[$key] = $candidate;
            }
        }

        return $found;
    }

    private function isTrashed(Model $record): bool
    {
        return method_exists($record, 'trashed') && $record->trashed();
    }
}
