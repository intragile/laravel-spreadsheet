<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Events;

use IntrAgile\LaravelSpreadsheet\Import\ImportResult;

final class ImportCompleted
{
    public function __construct(
        public readonly ImportResult $result,
        public readonly string $importClass,
    ) {}
}
