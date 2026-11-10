<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Mapping;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Mapping\MappingSuggester;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use InvalidArgumentException;

final class MappingSuggesterTest extends TestCase
{
    /** @param  list<Field>  $fields */
    private function import(array $fields): Import
    {
        return new class($fields) extends Import
        {
            /** @param  list<Field>  $fields */
            public function __construct(private array $fields) {}

            public function model(): string
            {
                return Partner::class;
            }

            public function fields(): array
            {
                return $this->fields;
            }
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int|string, mixed>>  $samples
     */
    private function suggest(Import $import, array $headers, array $samples = []): ColumnMapping
    {
        return (new MappingSuggester)->suggest($import, $headers, $samples);
    }

    public function test_exact_header_scores_100_and_is_auto(): void
    {
        $match = $this->suggest($this->import([Field::make('city')]), ['City'])->columns[0];

        $this->assertSame('city', $match->field);
        $this->assertSame(100.0, $match->score);
        $this->assertSame('auto', $match->confidence);
    }

    public function test_whole_word_containment_scores_80(): void
    {
        $match = $this->suggest($this->import([Field::make('email', FieldType::Email)]), ['email cim'])->columns[0];

        $this->assertSame(80.0, $match->score);
        $this->assertSame('review', $match->confidence);
    }

    public function test_containment_must_be_bounded_by_words(): void
    {
        $mapping = $this->suggest($this->import([Field::make('tel')]), ['telepules']);

        $this->assertNull($mapping->columns[0]->field);
        $this->assertSame('none', $mapping->columns[0]->confidence);
    }

    public function test_word_jaccard_scores_between_60_and_80(): void
    {
        $match = $this->suggest($this->import([Field::make('x')->label('alpha beta gamma')]), ['beta gamma delta'])->columns[0];
        $this->assertEqualsWithDelta(60 + 20 * (2 / 4), $match->score, 0.001);
    }

    public function test_close_spelling_scores_at_least_70(): void
    {
        $match = $this->suggest($this->import([Field::make('telephone')]), ['telephon'])->columns[0];

        $this->assertGreaterThanOrEqual(70.0, $match->score);
    }

    public function test_empty_header_maps_nothing(): void
    {
        $mapping = $this->suggest($this->import([Field::make('email')]), ['', '---', ' ']);

        foreach ($mapping->columns as $match) {
            $this->assertNull($match->field);
        }
    }

    public function test_profile_bonus_and_exclusion(): void
    {
        $import = $this->import([Field::make('phone', FieldType::Phone)]);

        $fits = $this->suggest($import, ['phone nr'], [['06201234567', '+36301234567']])->columns[0];
        $this->assertSame(90.0, $fits->score);
        $this->assertSame('auto', $fits->confidence);

        $names = $this->suggest($import, ['phone nr'], [['Anna', 'Béla', 'Csaba', 'Dóra', 'Emese']])->columns[0];
        $this->assertNull($names->field, 'excluded by the value profile: 80 - 40 is below the threshold');
    }

    public function test_bonus_is_capped_at_100(): void
    {
        $match = $this->suggest($this->import([Field::make('email', FieldType::Email)]), ['email'], [['a@b.hu', 'c@d.hu']])->columns[0];

        $this->assertSame(100.0, $match->score);
    }

    public function test_assignment_prefers_total_over_greedy_pair(): void
    {
        // Column 0 "name" -> f1 exact (100) and f2 via synonym containment (80);
        // column 1 "name x" -> f1 containment (80) only.
        // Greedy: 0->f1, 1->none (100). Optimal: 0->f2, 1->f1 (160).
        $import = $this->import([
            Field::make('name'),
            Field::make('contact')->synonyms('name surname'),
        ]);
        $mapping = $this->suggest($import, ['name', 'name x']);

        $this->assertSame('name', $mapping->fieldFor(1));
        $this->assertSame('contact', $mapping->fieldFor(0));
    }

    public function test_duplicate_headers_use_their_own_column_samples(): void
    {
        $import = $this->import([Field::make('phone', FieldType::Phone), Field::make('mobile', FieldType::Phone)]);
        $mapping = $this->suggest($import, ['phone', 'phone'], [
            0 => ['Anna', 'Béla', 'Csaba', 'Dóra', 'Emese'],
            1 => ['06201234567', '+36301234567'],
        ]);

        $this->assertNull($mapping->fieldFor(0));
        $this->assertSame('phone', $mapping->fieldFor(1));
    }

    public function test_candidates_from_labels_synonyms_and_config(): void
    {
        $import = $this->import([
            Field::make('tax_number')->label(['en' => 'Tax number', 'hu' => 'Adószám', 'de' => 'Steuernummer']),
        ]);

        config(['spreadsheet.import.locales' => ['en']]);
        $this->assertSame('tax_number', $this->suggest($import, ['Tax number'])->fieldFor(0));
        $this->assertNull($this->suggest($import, ['Adószám'])->fieldFor(0), 'hu label ignored when hu is not a candidate locale');

        config(['spreadsheet.import.locales' => ['en', 'hu']]);
        $this->assertSame('tax_number', $this->suggest($import, ['Adószám'])->fieldFor(0));
        $this->assertNull($this->suggest($import, ['Steuernummer'])->fieldFor(0));
        $this->assertSame('tax_number', $this->suggest($import, ['adóazonosító'])->fieldFor(0), 'from config synonyms with hu');

        config(['spreadsheet.import.locales' => ['en']]);
        $this->assertNull($this->suggest($import, ['adóazonosító'])->fieldFor(0));

        $any = $this->import([Field::make('x')->label('Anything'), Field::make('y')->synonyms('wibble')]);
        $this->assertSame('x', $this->suggest($any, ['anything'])->fieldFor(0));
        $this->assertSame('y', $this->suggest($any, ['wibble'])->fieldFor(0));
    }

    public function test_sparse_scores_never_map_a_column_at_zero(): void
    {
        $import = $this->import([Field::make('email', FieldType::Email), Field::make('city'), Field::make('zip')->label('city zip')]);
        $mapping = $this->suggest($import, ['email cim', 'email', 'city', 'Megjegyzés']);

        $this->assertSame([null, 'email', 'city', null], array_map($mapping->fieldFor(...), [0, 1, 2, 3]));

        foreach ($mapping->columns as $match) {
            $this->assertSame($match->field === null, $match->score === 0.0 || $match->confidence === 'none');
            $this->assertSame($match->field === null, $match->confidence === 'none');
        }
    }

    public function test_default_synonyms_do_not_map_unrelated_headers(): void
    {
        config(['spreadsheet.import.locales' => ['en', 'hu', 'de']]);
        $import = $this->import([
            Field::make('tax_number'), Field::make('email', FieldType::Email), Field::make('date', FieldType::Date),
            Field::make('domain'), Field::make('address'),
        ]);
        $mapping = $this->suggest($import, ['No', 'ID', 'Tartomány', 'Tag', 'Levelezési cím']);

        $this->assertNull($mapping->fieldFor(0));
        $this->assertNull($mapping->fieldFor(1), '"ID" must not score containment against "tax id"');
        $this->assertNull($mapping->fieldFor(2));
        $this->assertNull($mapping->fieldFor(3));
        $this->assertSame('address', $mapping->fieldFor(4));
    }

    public function test_default_synonyms_cover_common_fields(): void
    {
        config(['spreadsheet.import.locales' => ['en', 'hu', 'de', 'es']]);
        $import = $this->import([Field::make('zip'), Field::make('city'), Field::make('phone'), Field::make('first_name')]);

        $mapping = $this->suggest($import, ['Irányítószám', 'Telepules', 'Telefonszám', 'Vorname']);

        $this->assertSame(['zip', 'city', 'phone', 'first_name'], array_map($mapping->fieldFor(...), [0, 1, 2, 3]));
    }

    public function test_200_columns_by_50_fields_under_one_second(): void
    {
        $fields = [];
        for ($i = 0; $i < 50; $i++) {
            $fields[] = Field::make('field_'.$i)->label('Field label '.$i)->synonyms('syn '.$i, 'alias '.$i);
        }
        $headers = [];
        for ($i = 0; $i < 200; $i++) {
            $headers[$i] = $i < 50 ? 'Field label '.$i : 'unrelated column '.$i;
        }
        $samples = array_fill(0, 200, ['alpha', 'beta', 'gamma']);

        $start = microtime(true);
        $mapping = $this->suggest($this->import($fields), $headers, $samples);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed);
        $this->assertSame('field_7', $mapping->fieldFor(7));
    }

    public function test_confirmation_flow_and_overrides(): void
    {
        $import = $this->import([Field::make('email', FieldType::Email)->required(), Field::make('city')]);
        $mapping = $this->suggest($import, ['email cim', 'zzz']);

        $this->assertSame('review', $mapping->columns[0]->confidence);
        $this->assertSame([], $mapping->missingRequiredFields());
        $this->assertTrue($mapping->needsConfirmation());
        $this->assertFalse($mapping->confirm()->needsConfirmation());

        $unmapped = $this->suggest($import, ['zzz']);
        $this->assertSame(['email'], $unmapped->missingRequiredFields());
        $this->assertTrue($unmapped->needsConfirmation());

        $manual = $unmapped->with(0, 'email');
        $this->assertSame('email', $manual->fieldFor(0));
        $this->assertSame([], $manual->missingRequiredFields());
        $this->assertFalse($manual->needsConfirmation());
        $this->assertTrue($unmapped->needsConfirmation(), 'the original is untouched');

        $moved = $mapping->with(1, 'email');
        $this->assertSame('email', $moved->fieldFor(1));
        $this->assertNull($moved->fieldFor(0), 'a field maps to one column only');
        $this->assertNull($moved->with(1, null)->fieldFor(1));
        $this->assertSame(['email'], $moved->with(1, null)->missingRequiredFields());
    }

    public function test_with_rejects_unknown_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->suggest($this->import([Field::make('a')]), ['a'])->with(5, 'a');
    }

    public function test_mapping_round_trips_through_array(): void
    {
        $import = $this->import([Field::make('email', FieldType::Email)->required(), Field::make('city')]);
        $mapping = $this->suggest($import, ['email', 'city'], [['a@b.hu'], ['Pécs']])->with(1, 'city');

        $copy = ColumnMapping::fromArray((array) json_decode(json_encode($mapping->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertEquals($mapping, $copy);
    }
}
