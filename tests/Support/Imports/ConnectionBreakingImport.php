<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Breaks the chunk transaction in the saved() hook of the row named "Bad": either the whole
 * transaction is rolled back (as a server does when it aborts it) or a lost-connection error
 * is thrown.
 */
final class ConnectionBreakingImport extends PartnerImport
{
    public static bool $lostConnection = false;

    public function saved(Model $record, array $values, bool $created): void
    {
        if ($values['name'] !== 'Bad') {
            return;
        }

        if (self::$lostConnection) {
            throw new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }

        $record->getConnection()->rollBack(0);

        throw new RuntimeException('The transaction was rolled back.');
    }
}
