<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

enum FieldType: string
{
    case Text = 'text';
    case Email = 'email';
    case Phone = 'phone';
    case Date = 'date';
    case DateTime = 'date_time';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Enum = 'enum';
    case Country = 'country';
    case Currency = 'currency';
    case Language = 'language';
    case Domain = 'domain';
}
