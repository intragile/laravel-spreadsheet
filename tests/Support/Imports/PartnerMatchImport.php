<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;

final class PartnerMatchImport extends Import
{
    /** @param  list<MatchRule>  $rules */
    public function __construct(private readonly array $rules) {}

    public function model(): string
    {
        return Partner::class;
    }

    public function fields(): array
    {
        return [
            Field::make('name'),
            Field::make('email', FieldType::Email),
            Field::make('phone', FieldType::Phone),
            Field::make('domain', FieldType::Domain),
            Field::make('city'),
        ];
    }

    public function matchRules(): array
    {
        return $this->rules;
    }
}
