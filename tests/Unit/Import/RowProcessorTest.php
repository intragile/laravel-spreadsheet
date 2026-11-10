<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Import;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Import\Pipeline\RowProcessor;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMatch;
use IntrAgile\LaravelSpreadsheet\Mapping\ValueProfile;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizerRegistry;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class RowProcessorTest extends TestCase
{
    /**
     * @param  list<Field>  $fields
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>|null  $updateRules
     */
    private function import(array $fields, array $rules = [], ?array $updateRules = null): Import
    {
        return new class($fields, $rules, $updateRules) extends Import
        {
            /**
             * @param  list<Field>  $fields
             * @param  array<string, mixed>  $rules
             * @param  array<string, mixed>|null  $updateRules
             */
            public function __construct(private array $fields, private array $rules, private ?array $updateRules) {}

            public function model(): string
            {
                return Partner::class;
            }

            public function fields(): array
            {
                return $this->fields;
            }

            public function rules(): array
            {
                return $this->rules;
            }

            public function updateRules(): array
            {
                return $this->updateRules ?? $this->rules;
            }
        };
    }

    /** @param  list<array{0: string, 1: ?ValueProfile}>  $columns */
    private function mapping(array $columns): ColumnMapping
    {
        $matches = [];

        foreach ($columns as $index => [$field, $profile]) {
            $matches[] = new ColumnMatch($index, 'Col '.$index, $field, 100.0, ColumnMatch::AUTO, $profile ?? new ValueProfile);
        }

        return new ColumnMapping($matches);
    }

    /** @param  list<array{0: string, 1: ?ValueProfile}>  $columns */
    private function processor(Import $import, array $columns): RowProcessor
    {
        return new RowProcessor($import, $this->mapping($columns), new NormalizerRegistry, app(ValidationFactory::class));
    }

    public function test_normalization_error_becomes_row_error_with_field_label(): void
    {
        $import = $this->import([Field::make('born', FieldType::Date)->label(['en' => 'Birth date', 'hu' => 'Születési dátum'])]);
        $processor = $this->processor($import, [['born', null]]);

        $row = $processor->process(5, ['not a date']);

        $this->assertFalse($row->isValid());
        $this->assertSame(5, $row->rowNumber);
        $this->assertSame(['Birth date: "not a date" is not a valid date.'], $row->errors);

        app()->setLocale('hu');
        $row = $processor->process(6, ['nem dátum']);
        $this->assertSame(['Születési dátum: „nem dátum” nem érvényes dátum.'], $row->errors);
    }

    public function test_field_label_falls_back_to_any_locale_then_key(): void
    {
        $import = $this->import([
            Field::make('a', FieldType::Integer)->label('Any label'),
            Field::make('b', FieldType::Integer),
        ]);
        $row = $this->processor($import, [['a', null], ['b', null]])->process(1, ['x', 'y']);

        $this->assertSame(['Any label: "x" is not a valid whole number.', 'b: "y" is not a valid whole number.'], $row->errors);
    }

    public function test_validator_uses_rules_for_create_and_update_rules_for_update(): void
    {
        $import = $this->import(
            [Field::make('email', FieldType::Email)],
            ['email' => ['required', 'email', 'max:5']],
            ['email' => ['nullable', 'email']],
        );
        $processor = $this->processor($import, [['email', null]]);

        $this->assertFalse($processor->process(1, ['person@example.com'])->isValid());
        $this->assertTrue($processor->process(1, ['person@example.com'], true)->isValid());
        $this->assertTrue($processor->process(1, [null], true)->isValid());
    }

    public function test_validator_runs_on_normalized_values(): void
    {
        $import = $this->import([Field::make('amount', FieldType::Decimal)], ['amount' => 'numeric|min:1000']);
        $row = $this->processor($import, [['amount', null]])->process(1, ['1 234,56']);

        $this->assertTrue($row->isValid(), implode('; ', $row->errors));
        $this->assertEquals(1234.56, $row->values['amount']);
    }

    public function test_required_field_blank_fails(): void
    {
        $import = $this->import([Field::make('name')->label('Name')->required()]);
        $processor = $this->processor($import, [['name', null]]);

        $row = $processor->process(1, ['   ']);
        $this->assertFalse($row->isValid());
        $this->assertCount(1, $row->errors);
        $this->assertStringContainsString('Name', $row->errors[0]);

        $this->assertTrue($processor->process(2, ['Acme'])->isValid());
    }

    public function test_optional_blank_passes_typed_rules(): void
    {
        $import = $this->import([Field::make('email', FieldType::Email)], ['email' => 'email']);

        $this->assertTrue($this->processor($import, [['email', null]])->process(1, [null])->isValid());
    }

    public function test_per_column_context_from_profile(): void
    {
        $import = $this->import([Field::make('a', FieldType::Decimal), Field::make('b', FieldType::Decimal)]);
        $processor = $this->processor($import, [
            ['a', new ValueProfile(decimalSeparator: ',')],
            ['b', new ValueProfile(decimalSeparator: '.')],
        ]);

        $row = $processor->process(1, ['1.234', '1.234']);

        $this->assertEquals(1234, $row->values['a']);
        $this->assertEquals(1.234, $row->values['b']);
    }

    public function test_unmapped_columns_are_ignored_and_original_cells_kept(): void
    {
        $import = $this->import([Field::make('name')], ['name' => 'required']);
        $cells = ['Acme', 'ignored note'];
        $row = $this->processor($import, [['name', null]])->process(3, $cells);

        $this->assertSame($cells, $row->cells);
        $this->assertSame(['name' => 'Acme'], $row->values);
    }

    public function test_warnings_are_translated(): void
    {
        $import = $this->import([Field::make('currency', FieldType::Currency)]);
        $row = $this->processor($import, [['currency', null]])->process(1, ['€']);

        $this->assertTrue($row->isValid());
        $this->assertSame(['currency: Currency "€" was read as EUR.'], $row->warnings);
    }

    public function test_failed_normalization_skips_validation_of_that_field(): void
    {
        $import = $this->import([Field::make('born', FieldType::Date)->required()], ['born' => 'required']);
        $row = $this->processor($import, [['born', null]])->process(1, ['garbage']);

        $this->assertCount(1, $row->errors);
    }

    public function test_unmapped_required_field_fails_on_create_only(): void
    {
        $import = $this->import([Field::make('name')->label('Name'), Field::make('city')->label('City')->required()], ['city' => 'string']);
        $processor = $this->processor($import, [['name', null]]);

        $create = $processor->process(1, ['Acme']);
        $this->assertFalse($create->isValid());
        $this->assertCount(1, $create->errors);
        $this->assertStringContainsString('City', $create->errors[0]);

        $this->assertTrue($processor->process(1, ['Acme'], true)->isValid());
    }

    public function test_required_is_injected_on_create_but_not_on_update(): void
    {
        $import = $this->import(
            [Field::make('name')->required()],
            ['name' => ['string']],
            ['name' => ['nullable', 'string']],
        );
        $processor = $this->processor($import, [['name', null]]);

        $this->assertFalse($processor->process(1, [''])->isValid());
        $this->assertTrue($processor->process(1, [''], true)->isValid());
    }

    public function test_sensitive_field_messages_leave_the_value_out(): void
    {
        $import = $this->import([
            Field::make('born', FieldType::Date)->label(['en' => 'Birth date'])->sensitive(),
            Field::make('code')->label(['en' => 'Code'])->sensitive()->normalizeUsing(
                static fn (mixed $value): NormalizedValue => NormalizedValue::error('Value :value is wrong.', ['value' => $value]),
            ),
        ]);

        $row = $this->processor($import, [['born', null], ['code', null]])->process(2, ['31.31.2020', 'TOP-SECRET']);

        $this->assertSame(['Birth date: Not a valid date.', 'Code: Value *** is wrong.'], $row->errors);
    }
}
