<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use Closure;
use Illuminate\Database\Eloquent\Model;
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;

class PartnerImport extends Import
{
    /**
     * @param  MatchPolicy|Closure(Model, array<string, mixed>): ?array<string, mixed>  $policy
     * @param  list<MatchRule>  $rules
     */
    public function __construct(
        private readonly MatchPolicy|Closure $policy = MatchPolicy::Skip,
        private readonly array $rules = [],
    ) {}

    public function key(): string
    {
        return 'partners';
    }

    public function model(): string
    {
        return Partner::class;
    }

    public function fields(): array
    {
        return [
            Field::make('name')->label(['en' => 'Name', 'hu' => 'Név'])->required(),
            Field::make('email', FieldType::Email)->label(['en' => 'Email', 'hu' => 'E-mail']),
            Field::make('phone', FieldType::Phone)->label(['en' => 'Phone', 'hu' => 'Telefon']),
            Field::make('tax_number')->label(['en' => 'Tax number', 'hu' => 'Adószám'])->sensitive(),
            Field::make('city')->label(['en' => 'City', 'hu' => 'Város']),
            Field::make('revenue', FieldType::Decimal)->label(['en' => 'Revenue', 'hu' => 'Árbevétel']),
            Field::make('founded_at', FieldType::Date)->label(['en' => 'Founded at', 'hu' => 'Alapítva']),
        ];
    }

    public function rules(): array
    {
        return ['email' => ['email']];
    }

    public function matchRules(): array
    {
        return $this->rules;
    }

    public function onMatch(): MatchPolicy|Closure
    {
        return $this->policy;
    }
}
