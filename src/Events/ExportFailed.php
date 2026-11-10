<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Events;

final class ExportFailed
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly string $exportClass,
        public readonly string $message,
    ) {}
}
