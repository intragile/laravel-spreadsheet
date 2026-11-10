<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use Illuminate\Database\Eloquent\Model;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\ChildMerger;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Domain;

/**
 * A domain list whose email, phone and name columns become contacts of the domain.
 */
final class OutreachImport extends Import
{
    public function model(): string
    {
        return Domain::class;
    }

    public function fields(): array
    {
        return [
            Field::make('domain', FieldType::Domain)->required(),
            Field::make('email', FieldType::Email),
            Field::make('phone', FieldType::Phone),
            Field::make('name'),
        ];
    }

    public function matchRules(): array
    {
        return [MatchRule::sure('domain')];
    }

    public function onMatch(): MatchPolicy
    {
        return MatchPolicy::FillMissing;
    }

    public function fill(Model $record, array $values, bool $exists): void
    {
        if (! $exists) {
            $record->forceFill(['domain' => $values['domain']]);
        }
    }

    public function saved(Model $record, array $values, bool $created): void
    {
        assert($record instanceof Domain);

        $name = is_string($values['name'] ?? null) && trim($values['name']) !== '' ? $values['name'] : $record->domain;

        (new ChildMerger)->merge(
            $record->contacts(),
            ['name' => $name, 'email' => $values['email'] ?? null, 'phone' => $values['phone'] ?? null],
            ['email' => 'email', 'phone' => 'phone'],
        );
    }
}
