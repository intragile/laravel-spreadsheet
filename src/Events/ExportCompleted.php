<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Events;

final class ExportCompleted
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
        public readonly int $rows,
        public readonly string $exportClass,
    ) {}
}
