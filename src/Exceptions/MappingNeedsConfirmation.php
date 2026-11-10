<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Exceptions;

use IntrAgile\LaravelSpreadsheet\Mapping\ColumnMapping;
use RuntimeException;

/**
 * Thrown by a strict import whose column mapping has uncertain matches or unmapped required fields.
 */
final class MappingNeedsConfirmation extends RuntimeException
{
    public function __construct(public readonly ColumnMapping $mapping)
    {
        $missing = $mapping->missingRequiredFields();

        parent::__construct($missing === []
            ? 'The column mapping needs confirmation.'
            : 'The column mapping needs confirmation; required fields are not mapped: '.implode(', ', $missing).'.');
    }
}
