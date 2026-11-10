<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use Illuminate\Database\Eloquent\Model;
use ValueError;

/** Fails in the saved() hook for the row named "Bad" with an Error, not an Exception. */
final class ThrowingSavedImport extends PartnerImport
{
    public function saved(Model $record, array $values, bool $created): void
    {
        if ($values['name'] === 'Bad') {
            throw new ValueError('"x" is not a valid backing value');
        }
    }
}
