<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support\Imports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;

/**
 * Fails in the saved() hook of the row named "Bad" the way $mode says: 'rollback' rolls the whole
 * transaction back (as a server does when it aborts it) and throws, 'lost' throws a database
 * lost-connection error, 'reset' throws a non-database error whose text looks like one.
 */
final class ConnectionBreakingImport extends PartnerImport
{
    /** @var 'rollback'|'lost'|'reset' */
    public static string $mode = 'rollback';

    public function saved(Model $record, array $values, bool $created): void
    {
        if ($values['name'] !== 'Bad') {
            return;
        }

        if (self::$mode === 'lost') {
            throw new QueryException('mysql', 'insert into partners', [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        }

        if (self::$mode === 'reset') {
            throw new RuntimeException('Connection reset by peer');
        }

        $record->getConnection()->rollBack(0);

        throw new RuntimeException('The transaction was rolled back.');
    }
}
