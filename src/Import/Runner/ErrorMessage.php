<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Runner;

use PDOException;
use Throwable;

/**
 * The message of an exception as it may be shown to the user: database errors carry SQL and
 * bound values (sensitive cells included), so they are replaced by a generic text unless the
 * application runs in debug mode.
 *
 * @internal
 */
final class ErrorMessage
{
    /** @param  string  $translationKey  Message used for database errors, under `spreadsheet::messages.`. */
    public static function for(Throwable $e, string $translationKey): string
    {
        if ($e instanceof PDOException && ! config('app.debug')) {
            return (string) __('spreadsheet::messages.'.$translationKey);
        }

        return $e->getMessage();
    }
}
