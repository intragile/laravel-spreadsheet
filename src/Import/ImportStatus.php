<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import;

enum ImportStatus: string
{
    case Completed = 'completed';
    case NeedsConfirmation = 'needs_confirmation';
}
