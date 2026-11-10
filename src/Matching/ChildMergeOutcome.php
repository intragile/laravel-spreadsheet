<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

enum ChildMergeOutcome: string
{
    case Added = 'added';
    case Filled = 'filled';
    case Unchanged = 'unchanged';
}
