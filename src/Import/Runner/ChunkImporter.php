<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
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
use PDOException;
use Throwable;

/**
 * Imports one chunk of rows: normalizes and validates them, drops in-file duplicates, matches
 * the rest against existing records and writes them in one transaction with a savepoint per row.
 *
 * A row that created a record records its match keys, so a later row with the same key is a
 * duplicate of it. Rows matching a record that existed before the import do not record keys:
 * several rows may update the same existing record (adding children, for example).
 *
 * @internal
 */
final class ChunkImporter
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

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

    /**
     * Imports a chunk. When the database aborts the chunk's transaction because of a deadlock or
     * another concurrency error, the chunk is run once more from scratch; a second such error
     * fails the import.
     *
     * @param  array<int, array<int, mixed>>  $chunk  Row number => cells.
     */
    public function import(array $chunk): void
    {
        try {
            $outcome = $this->attempt($chunk);
        } catch (Throwable $e) {
            if (! $this->isConcurrencyError($e)) {
                throw $e;
            }

            $outcome = $this->attempt($chunk);
        }

        $this->apply($outcome);
    }

    /**
     * Runs one attempt of a chunk. Nothing outside the database transaction changes, so a failed
     * attempt leaves no trace in the counters, the failed-rows file or the key store.
     *
     * @param  array<int, array<int, mixed>>  $chunk
     */
    private function attempt(array $chunk): ChunkOutcome
    {
        $outcome = new ChunkOutcome;
        $candidates = [];

        foreach ($chunk as $number => $cells) {
            $row = $this->processor->process($number, $cells);
            $update = null;

            if (! $row->isValid()) {
                $update = $this->mayUpdate ? $this->processor->process($number, $cells, true) : null;

                if ($update === null || ! $update->isValid()) {
                    $outcome->fail($number, RowStatus::Error, $this->join($row->errors), $cells);

                    continue;
                }
            }

            $candidates[$number] = [$row, $update];
        }

        $matches = $candidates === []
            ? []
            : $this->matcher->match($this->import, array_map(static fn (array $candidate): array => $candidate[0]->values, $candidates));

        /** @var array<int, array{0: ProcessedRow, 1: Model|null, 2: array<string, array{0: string, 1: MatchStrength}>}> $writes */
        $writes = [];

        foreach ($candidates as $number => [$row, $update]) {
            $match = $matches[$number] ?? null;
            $keys = $this->keysOf($row->values);

            if ($this->rejectDuplicate($outcome, $number, $row->cells, $this->recordedBefore($keys))) {
                continue;
            }

            if ($match === null) {
                if ($row->isValid()) {
                    $writes[$number] = [$row, null, $keys];
                } else {
                    $outcome->fail($number, RowStatus::Error, $this->join($row->errors), $row->cells);
                }

                continue;
            }

            if ($match->trashed) {
                $outcome->fail($number, RowStatus::Error, $this->message('matches_trashed'), $row->cells);

                continue;
            }

            if ($match->strength === MatchStrength::Suspicious) {
                $key = $match->record->getKey();
                $outcome->fail($number, RowStatus::Review, $this->message('possible_duplicate', ['id' => is_scalar($key) ? (string) $key : '']), $row->cells);

                continue;
            }

            if ($this->policy === MatchPolicy::Skip) {
                $outcome->skipped++;

                continue;
            }

            $update ??= $this->processor->process($number, $row->cells, true);

            if (! $update->isValid()) {
                $outcome->fail($number, RowStatus::Error, $this->join($update->errors), $row->cells);

                continue;
            }

            $writes[$number] = [$update, $match->record, $keys];
        }

        if ($writes !== []) {
            $this->connection->transaction(fn () => $this->write($writes, $outcome));
        }

        return $outcome;
    }

    /**
     * Writes the rows in order, each in its own savepoint so a failing row rolls back alone. A
     * row whose key a row of this chunk has just created is a duplicate of that row; a row whose
     * create failed leaves its keys free for the rows after it.
     *
     * @param  array<int, array{0: ProcessedRow, 1: Model|null, 2: array<string, array{0: string, 1: MatchStrength}>}>  $writes
     */
    private function write(array $writes, ChunkOutcome $outcome): void
    {
        /** @var array<string, array<string, int>> $created Rule id => key => row number. */
        $created = [];
        $level = $this->connection->transactionLevel();

        foreach ($writes as $number => [$row, $existing, $keys]) {
            $first = null;

            foreach ($keys as $ruleId => [$key, $strength]) {
                if (isset($created[$ruleId][$key])) {
                    $first = [$created[$ruleId][$key], $strength];

                    break;
                }
            }

            if ($this->rejectDuplicate($outcome, $number, $row->cells, $first)) {
                continue;
            }

            try {
                $result = $this->connection->transaction(fn (): string => $existing === null
                    ? $this->create($row->values)
                    : $this->update($existing, $row->values));
            } catch (Throwable $e) {
                // Any throwable fails only its row, a TypeError or ValueError from a hook included.
                // After a concurrency error the database has rolled back the whole transaction:
                // let the chunk start over.
                if ($this->isConcurrencyError($e)) {
                    throw $e;
                }

                // A lost database connection, or a chunk transaction that is no longer open, means
                // the rows written so far were rolled back: counting them would report records that
                // do not exist, so the import stops. Only database errors are checked for a lost
                // connection; a hook's own error mentioning a connection fails just its row.
                if ((($e instanceof QueryException || $e instanceof PDOException) && $this->causedByLostConnection($e))
                    || $this->connection->transactionLevel() < $level) {
                    throw $e;
                }

                if ($existing !== null) {
                    $this->resync($existing);
                }

                $outcome->fail($number, RowStatus::Error, ErrorMessage::for($e, 'database_error'), $row->cells);

                continue;
            }

            match ($result) {
                'created' => $outcome->created++,
                'updated' => $outcome->updated++,
                default => $outcome->skipped++,
            };

            if ($result === 'created') {
                foreach ($keys as $ruleId => [$key]) {
                    $created[$ruleId][$key] = $number;
                    $outcome->createdKeys[] = [$ruleId, $key, $number];
                }
            }
        }
    }

    /** Commits the outcome of a successful attempt to the counters, the key store and the failed-rows file. */
    private function apply(ChunkOutcome $outcome): void
    {
        foreach ($outcome->createdKeys as [$ruleId, $key, $number]) {
            $this->keys?->firstSeen($ruleId, $key, $number);
        }

        $this->created += $outcome->created;
        $this->updated += $outcome->updated;
        $this->skipped += $outcome->skipped;

        $failures = $outcome->failures;
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
     * Records a duplicate row in the outcome.
     *
     * @param  array<int, mixed>  $cells
     * @param  array{0: int, 1: MatchStrength}|null  $first  The earlier row and the strength of the shared rule.
     */
    private function rejectDuplicate(ChunkOutcome $outcome, int $number, array $cells, ?array $first): bool
    {
        if ($first === null) {
            return false;
        }

        [$row, $strength] = $first;

        $strength === MatchStrength::Sure
            ? $outcome->fail($number, RowStatus::Skipped, $this->message('duplicate_in_file', ['row' => $row]), $cells)
            : $outcome->fail($number, RowStatus::Review, $this->message('possible_duplicate_in_file', ['row' => $row]), $cells);

        return true;
    }

    /** Reloads a record whose update was rolled back, so rows sharing the instance see its stored state. */
    private function resync(Model $record): void
    {
        try {
            $record->refresh();
        } catch (Throwable) {
            // A record that cannot be reloaded is left as is; its own row is reported already.
        }
    }

    private function isConcurrencyError(Throwable $e): bool
    {
        return $e instanceof DeadlockException || $this->causedByConcurrencyError($e);
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
     * The row's key for every match rule it has all values for.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{0: string, 1: MatchStrength}> Rule id => [key, rule strength].
     */
    private function keysOf(array $values): array
    {
        if ($this->keys === null) {
            return [];
        }

        $keys = [];

        foreach ($this->rules as $position => $rule) {
            $parts = $this->matchKey->parts($this->canonicalizers[$position], static fn (string $field): mixed => $values[$field] ?? null);

            if ($parts !== null) {
                $keys[$rule->id()] = [MatchKey::hash($rule, $parts), $rule->strength];
            }
        }

        return $keys;
    }

    /**
     * The row of an earlier chunk that created a record with one of these keys.
     *
     * @param  array<string, array{0: string, 1: MatchStrength}>  $keys
     * @return array{0: int, 1: MatchStrength}|null
     */
    private function recordedBefore(array $keys): ?array
    {
        foreach ($keys as $ruleId => [$key, $strength]) {
            $first = $this->keys?->seen($ruleId, $key);

            if ($first !== null) {
                return [$first, $strength];
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
