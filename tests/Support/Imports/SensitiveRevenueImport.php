<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;

final class SensitiveRevenueImport extends PartnerImport
{
    public function fields(): array
    {
        return [
            Field::make('name')->label(['en' => 'Name', 'hu' => 'Név'])->required(),
            Field::make('email', FieldType::Email)->label(['en' => 'Email', 'hu' => 'E-mail']),
            Field::make('revenue', FieldType::Decimal)->label(['en' => 'Revenue', 'hu' => 'Árbevétel'])->sensitive(),
        ];
    }
}
