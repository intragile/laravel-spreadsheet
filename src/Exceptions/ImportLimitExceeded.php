<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Exceptions;

use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use Throwable;

/**
 * Thrown when an uploaded file exceeds a reader safety limit. The message is translated and
 * keeps the reader's own reason; the original exception is the previous one.
 */
final class ImportLimitExceeded extends LimitExceeded
{
    /**
     * @param  bool  $partial  True when the limit was reached during the import, after earlier
     *                         chunks may have been committed.
     */
    public function __construct(string $message, public readonly bool $partial, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
