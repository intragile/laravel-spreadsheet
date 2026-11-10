<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use Closure;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use IntrAgile\LaravelSpreadsheet\Import\FailedRows\FailedRowsWriter;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\ProcessedRow;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowProcessor;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowStatus;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\Canonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\KeyStore;
use IntrAgile\LaravelSpreadsheet\Matching\MatchKey;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Matching\MatchStrength;
use IntrAgile\LaravelSpreadsheet\Matching\RecordMatcher;

/**
 * Imports one chunk of rows: normalizes and validates them, drops in-file duplicates, matches
 * the rest against existing records and writes them in one transaction with a savepoint per row.
 *
 * A row that would create a record records its match keys, so a later row with the same key is
 * a duplicate of it. Rows matching a record that existed before the import do not record keys:
 * several rows may update the same existing record (adding children, for example).
 *
 * @internal
 */
final class ChunkImporter
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $failed = 0;

    /** @var list<MatchRule> */
    private readonly array $rules;

    /** @var list<array<string, Canonicalizer>> Canonicalizers of each rule, by position. */
    private readonly array $canonicalizers;

    /** @var MatchPolicy|Closure(Model, array<string, mixed>): ?array<string, mixed> */
    private readonly MatchPolicy|Closure $policy;

    private readonly RecordMatcher $matcher;

    /** Rows invalid for a new record are still matched when a matched record could be updated. */
    private readonly bool $mayUpdate;

    public function __construct(
        private readonly Import $import,
        private readonly RowProcessor $processor,
        private readonly FailedRowsWriter $failedRows,
        private readonly ?KeyStore $keys,
        private readonly MatchKey $matchKey,
        private readonly ConnectionInterface $connection,
    ) {
        $this->rules = $import->matchRules();
        $this->canonicalizers = array_map(fn (MatchRule $rule): array => $matchKey->canonicalizers($import, $rule), $this->rules);
        $this->policy = $import->onMatch();
        $this->matcher = $import->matcher();
        $this->mayUpdate = $this->policy !== MatchPolicy::Skip;
    }

    /** @param  array<int, array<int, mixed>>  $chunk  Row number => cells. */
    public function import(array $chunk): void
    {
        /** @var array<int, array{0: RowStatus, 1: string, 2: array<int, mixed>}> $failures */
        $failures = [];
        $candidates = [];

        foreach ($chunk as $number => $cells) {
            $row = $this->processor->process($number, $cells);
            $update = null;

            if (! $row->isValid()) {
                $update = $this->mayUpdate ? $this->processor->process($number, $cells, true) : null;

                if ($update === null || ! $update->isValid()) {
                    $failures[$number] = [RowStatus::Error, $this->join($row->errors), $cells];

                    continue;
                }
            }

            $candidates[$number] = [$row, $update];
        }

        $matches = $candidates === []
            ? []
            : $this->matcher->match($this->import, array_map(static fn (array $candidate): array => $candidate[0]->values, $candidates));

        /** @var array<int, array{0: ProcessedRow, 1: Model|null}> $writes */
        $writes = [];

        foreach ($candidates as $number => [$row, $update]) {
            $match = $matches[$number] ?? null;
            $duplicate = $this->duplicateOf($row->values, $number, $match === null && $row->isValid());

            if ($duplicate !== null) {
                [$first, $strength] = $duplicate;
                $failures[$number] = $strength === MatchStrength::Sure
                    ? [RowStatus::Skipped, $this->message('duplicate_in_file', ['row' => $first]), $row->cells]
                    : [RowStatus::Review, $this->message('possible_duplicate_in_file', ['row' => $first]), $row->cells];

                continue;
            }

            if ($match === null) {
                if ($row->isValid()) {
                    $writes[$number] = [$row, null];
                } else {
                    $failures[$number] = [RowStatus::Error, $this->join($row->errors), $row->cells];
                }

                continue;
            }

            if ($match->trashed) {
                $failures[$number] = [RowStatus::Error, $this->message('matches_trashed'), $row->cells];

                continue;
            }

            if ($match->strength === MatchStrength::Suspicious) {
                $key = $match->record->getKey();
                $failures[$number] = [RowStatus::Review, $this->message('possible_duplicate', ['id' => is_scalar($key) ? (string) $key : '']), $row->cells];

                continue;
            }

            if ($this->policy === MatchPolicy::Skip) {
                $this->skipped++;

                continue;
            }

            $update ??= $this->processor->process($number, $row->cells, true);

            if (! $update->isValid()) {
                $failures[$number] = [RowStatus::Error, $this->join($update->errors), $row->cells];

                continue;
            }

            $writes[$number] = [$update, $match->record];
        }

        foreach ($this->write($writes) as $number => $outcome) {
            match ($outcome) {
                'created' => $this->created++,
                'updated' => $this->updated++,
                'skipped' => $this->skipped++,
                default => $failures[$number] = [RowStatus::Error, $outcome, $writes[$number][0]->cells],
            };
        }

        ksort($failures);

        foreach ($failures as [$status, $message, $cells]) {
            $this->failedRows->write($cells, $status, $message);

            if ($status === RowStatus::Error) {
                $this->failed++;
            } else {
                $this->skipped++;
            }
        }
    }

    /**
     * Writes the rows in one transaction, each in its own savepoint so a failing row rolls back alone.
     *
     * @param  array<int, array{0: ProcessedRow, 1: Model|null}>  $writes
     * @return array<int, string> Row number => 'created', 'updated', 'skipped' or an error message.
     */
    private function write(array $writes): array
    {
        if ($writes === []) {
            return [];
        }

        /** @var array<int, string> $outcomes */
        $outcomes = [];

        $this->connection->transaction(function () use ($writes, &$outcomes): void {
            foreach ($writes as $number => [$row, $existing]) {
                try {
                    $outcomes[$number] = $this->connection->transaction(fn (): string => $existing === null
                        ? $this->create($row->values)
                        : $this->update($existing, $row->values));
                } catch (Exception $e) {
                    $outcomes[$number] = $e instanceof QueryException && ! config('app.debug')
                        ? $this->message('database_error')
                        : $e->getMessage();
                }
            }
        });

        return $outcomes;
    }

    /** @param  array<string, mixed>  $values */
    private function create(array $values): string
    {
        $class = $this->import->model();
        $record = new $class;

        $this->import->fill($record, $values, false);
        $record->save();
        $this->import->saved($record, $values, true);

        return 'created';
    }

    /** @param  array<string, mixed>  $values */
    private function update(Model $record, array $values): string
    {
        if ($this->policy instanceof Closure) {
            $attributes = ($this->policy)($record, $values);

            if ($attributes === null) {
                return 'skipped';
            }

            $record->forceFill($attributes);
        } else {
            $this->import->fill($record, $values, true);
        }

        $record->save();
        $this->import->saved($record, $values, false);

        return 'updated';
    }

    /**
     * The earlier row of this file that created a record with one of the row's keys, and the
     * strength of the rule that matched. A row that will create a record records its keys.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: int, 1: MatchStrength}|null
     */
    private function duplicateOf(array $values, int $number, bool $record): ?array
    {
        if ($this->keys === null) {
            return null;
        }

        $keys = [];

        foreach ($this->rules as $position => $rule) {
            $parts = $this->matchKey->parts($this->canonicalizers[$position], static fn (string $field): mixed => $values[$field] ?? null);

            if ($parts === null) {
                continue;
            }

            $key = MatchKey::hash($rule, $parts);

            $first = $this->keys->seen($rule->id(), $key);

            if ($first !== null && $first !== $number) {
                return [$first, $rule->strength];
            }

            $keys[$rule->id()] = $key;
        }

        if ($record) {
            foreach ($keys as $ruleId => $key) {
                $this->keys->firstSeen($ruleId, $key, $number);
            }
        }

        return null;
    }

    /** @param  list<string>  $errors */
    private function join(array $errors): string
    {
        return implode(' ', $errors);
    }

    /** @param  array<string, string|int>  $replace */
    private function message(string $key, array $replace = []): string
    {
        return (string) __('spreadsheet::messages.'.$key, $replace);
    }
}
