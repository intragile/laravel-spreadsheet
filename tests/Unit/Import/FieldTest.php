<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Import;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\Canonicalizers\TextCanonicalizer;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use InvalidArgumentException;

final class FieldTest extends TestCase
{
    public function test_field_defaults(): void
    {
        $field = Field::make('name');

        $this->assertSame('name', $field->key);
        $this->assertSame(FieldType::Text, $field->type);
        $this->assertSame('name', $field->attribute());
        $this->assertSame([], $field->labels());
        $this->assertSame([], $field->synonymList());
        $this->assertSame([], $field->optionMap());
        $this->assertFalse($field->isRequired());
        $this->assertFalse($field->isSensitive());
    }

    public function test_fluent_configuration(): void
    {
        $field = Field::make('status', FieldType::Enum)
            ->label(['en' => 'Status', 'hu' => 'Állapot'])
            ->synonyms('state', 'stage')
            ->required()
            ->sensitive()
            ->column('state_code')
            ->options(['a' => 'Active', 'i' => ['en' => 'Inactive', 'hu' => 'Inaktív']]);

        $this->assertSame(['en' => 'Status', 'hu' => 'Állapot'], $field->labels());
        $this->assertSame(['state', 'stage'], $field->synonymList());
        $this->assertTrue($field->isRequired());
        $this->assertTrue($field->isSensitive());
        $this->assertSame('state_code', $field->attribute());
        $this->assertSame(
            ['a' => ['*' => 'Active'], 'i' => ['en' => 'Inactive', 'hu' => 'Inaktív']],
            $field->optionMap(),
        );
        $this->assertSame(['*' => 'Name'], Field::make('n')->label('Name')->labels());
    }

    public function test_field_type_values_are_snake_case(): void
    {
        $this->assertSame('date_time', FieldType::DateTime->value);
        $this->assertCount(13, FieldType::cases());
    }

    public function test_rule_id_includes_strength_and_fields(): void
    {
        $this->assertSame('sure|email|phone', MatchRule::sure('email', 'phone')->id());
        $this->assertSame('suspicious|name', MatchRule::suspicious('name')->id());
    }

    public function test_rule_canonicalizer_override_and_validation(): void
    {
        $c = new TextCanonicalizer;
        $rule = MatchRule::sure('name')->canonicalizeUsing('name', $c);

        $this->assertSame($c, $rule->canonicalizerFor('name'));
        $this->assertNull(MatchRule::sure('name')->canonicalizerFor('name'));

        $this->expectException(InvalidArgumentException::class);
        $rule->canonicalizeUsing('other', 'text');
    }

    public function test_import_defaults(): void
    {
        $import = new PartnerImportFixture;

        $this->assertSame('partner-import-fixture', $import->key());
        $this->assertSame([], $import->matchRules());
        $this->assertSame(MatchPolicy::Skip, $import->onMatch());
        $this->assertSame($import->rules(), $import->updateRules());
    }

    public function test_fill_writes_mapped_attributes(): void
    {
        $record = new Partner;
        (new PartnerImportFixture)->fill($record, ['name' => 'Acme', 'town' => 'Győr', 'ignored' => 'x'], false);

        $this->assertSame('Acme', $record->name);
        $this->assertSame('Győr', $record->city);
        $this->assertArrayNotHasKey('ignored', $record->getAttributes());
    }

    public function test_fill_missing_only_writes_blank_attributes(): void
    {
        $import = new PartnerImportFixture(MatchPolicy::FillMissing);
        $record = new Partner(['name' => 'Acme', 'city' => '  ']);

        $import->fill($record, ['name' => 'Changed', 'town' => 'Győr'], true);

        $this->assertSame('Acme', $record->name);
        $this->assertSame('Győr', $record->city);
    }

    public function test_overwrite_policy_replaces_existing_values(): void
    {
        $record = new Partner(['name' => 'Acme']);
        (new PartnerImportFixture(MatchPolicy::Overwrite))->fill($record, ['name' => 'Changed'], true);

        $this->assertSame('Changed', $record->name);
    }
}

final class PartnerImportFixture extends Import
{
    public function __construct(private readonly MatchPolicy $policy = MatchPolicy::Skip) {}

    public function model(): string
    {
        return Partner::class;
    }

    public function fields(): array
    {
        return [Field::make('name')->required(), Field::make('town')->column('city')];
    }

    public function onMatch(): MatchPolicy
    {
        return $this->policy;
    }

    public function rules(): array
    {
        return ['name' => ['required']];
    }
}
