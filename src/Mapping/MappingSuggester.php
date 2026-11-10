<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Support\HeaderNormalizer;

/**
 * Suggests which import field each file column holds. Every header is scored against every
 * field's names, the sample values nudge the score, and an optimal one-to-one assignment
 * picks the combination with the highest total.
 */
final class MappingSuggester
{
    private const AUTO_SCORE = 90.0;

    private const REVIEW_SCORE = 60.0;

    private const FIT_BONUS = 10.0;

    private const EXCLUDE_PENALTY = 40.0;

    /** Added per column position so the earlier of two equal candidates wins the field. */
    private const TIE_BREAK = 1e-6;

    /**
     * @param  array<int, string>  $headers  Column position => header text.
     * @param  array<int, array<int|string, mixed>>  $samplesByColumn  Column position => sample values.
     */
    public function suggest(Import $import, array $headers, array $samplesByColumn): ColumnMapping
    {
        $fields = $import->fields();
        $candidates = $this->candidates($fields);
        $fieldKeys = array_map(static fn (Field $field): string => $field->key, $fields);
        $sniffer = new ValueSniffer;

        $profiles = [];
        $scores = [];
        $assignable = [];
        $total = max(1, count($headers));
        $rank = 0;

        foreach ($headers as $column => $header) {
            $profiles[$column] = $sniffer->profile($samplesByColumn[$column] ?? []);
            $normalized = HeaderNormalizer::normalize($header);

            foreach ($fields as $index => $field) {
                $score = $normalized === '' ? 0.0 : $this->score($normalized, $candidates[$index], $field, $profiles[$column]);
                $scores[$column][$index] = $score;

                if ($score >= self::REVIEW_SCORE) {
                    $assignable[$column][$index] = $score + self::TIE_BREAK * ($total - $rank);
                }
            }

            $rank++;
        }

        $assignment = Assignment::maximize($assignable);
        $matches = [];

        foreach ($headers as $column => $header) {
            $index = $assignment[$column] ?? null;
            $score = $index === null ? 0.0 : ($scores[$column][$index] ?? 0.0);

            $matches[] = new ColumnMatch(
                (int) $column,
                $header,
                $index === null ? null : ($fieldKeys[$index] ?? null),
                $score,
                $this->confidence($score),
                $profiles[$column],
            );
        }

        $required = [];

        foreach ($fields as $field) {
            if ($field->isRequired()) {
                $required[] = $field->key;
            }
        }

        return new ColumnMapping($matches, $required);
    }

    /**
     * @param  list<string>  $candidates  Normalized, non-empty names of the field.
     */
    private function score(string $header, array $candidates, Field $field, ValueProfile $profile): float
    {
        $best = 0.0;

        foreach ($candidates as $candidate) {
            $best = max($best, $this->nameScore($header, $candidate));

            if ($best >= 100.0) {
                break;
            }
        }

        if ($best <= 0.0) {
            return 0.0;
        }

        $type = $this->profileType($field->type);

        if ($type !== null) {
            if ($profile->fits($type)) {
                $best = min(100.0, $best + self::FIT_BONUS);
            } elseif ($profile->excludes($type)) {
                $best = max(0.0, $best - self::EXCLUDE_PENALTY);
            }
        }

        return $best;
    }

    private function nameScore(string $header, string $candidate): float
    {
        if ($header === $candidate) {
            return 100.0;
        }

        [$short, $long] = strlen($header) <= strlen($candidate) ? [$header, $candidate] : [$candidate, $header];

        if (str_contains(' '.$long.' ', ' '.$short.' ')) {
            return 80.0;
        }

        $headerWords = explode(' ', $header);
        $candidateWords = explode(' ', $candidate);
        $union = count(array_unique([...$headerWords, ...$candidateWords]));
        $jaccard = count(array_intersect(array_unique($headerWords), array_unique($candidateWords))) / $union;

        if ($jaccard >= 0.5) {
            return 60.0 + 20.0 * $jaccard;
        }

        if (mb_strlen($header) >= 5) {
            similar_text($header, $candidate, $percent);

            if ($percent >= 85.0) {
                return 70.0;
            }
        }

        return 0.0;
    }

    /** @return 'auto'|'review'|'none' */
    private function confidence(float $score): string
    {
        return match (true) {
            $score >= self::AUTO_SCORE => ColumnMatch::AUTO,
            $score >= self::REVIEW_SCORE => ColumnMatch::REVIEW,
            default => ColumnMatch::NONE,
        };
    }

    private function profileType(FieldType $type): ?string
    {
        return match ($type) {
            FieldType::Email => 'email',
            FieldType::Phone => 'phone',
            FieldType::Date, FieldType::DateTime => 'date',
            FieldType::Integer => 'integer',
            FieldType::Decimal => 'decimal',
            FieldType::Boolean => 'boolean',
            default => null,
        };
    }

    /**
     * The normalized names each field is known by: its key, its labels in the configured
     * locales (and any-locale labels), its own synonyms and the configured synonyms.
     *
     * @param  list<Field>  $fields
     * @return list<list<string>>
     */
    private function candidates(array $fields): array
    {
        $locales = array_values(array_filter((array) config('spreadsheet.import.locales', ['en']), is_string(...)));
        $configured = (array) config('spreadsheet.import.synonyms', []);
        $result = [];

        foreach ($fields as $field) {
            $names = [$field->key];

            foreach ($field->labels() as $locale => $label) {
                if ($locale === Field::ANY_LOCALE || in_array($locale, $locales, true)) {
                    $names[] = $label;
                }
            }

            array_push($names, ...$field->synonymList());

            $byLocale = $configured[$field->key] ?? [];

            foreach ([Field::ANY_LOCALE, ...$locales] as $locale) {
                foreach (is_array($byLocale) && is_array($byLocale[$locale] ?? null) ? $byLocale[$locale] : [] as $synonym) {
                    if (is_string($synonym)) {
                        $names[] = $synonym;
                    }
                }
            }

            $normalized = array_map(HeaderNormalizer::normalize(...), $names);
            $result[] = array_values(array_unique(array_filter($normalized, static fn (string $name): bool => $name !== '')));
        }

        return $result;
    }
}
