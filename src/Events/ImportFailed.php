<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Events;

final class ImportFailed
{
    public function __construct(
        public readonly string $importClass,
        public readonly string $message,
    ) {}
}
