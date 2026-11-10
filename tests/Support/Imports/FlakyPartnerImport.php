<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use Closure;
use Illuminate\Database\Eloquent\Model;

/** A partner import whose saved() hook a test can make fail. */
final class FlakyPartnerImport extends PartnerImport
{
    /** @var (Closure(Model, array<string, mixed>): void)|null */
    public static ?Closure $onSaved = null;

    public function saved(Model $record, array $values, bool $created): void
    {
        if (self::$onSaved !== null) {
            (self::$onSaved)($record, $values);
        }
    }
}
