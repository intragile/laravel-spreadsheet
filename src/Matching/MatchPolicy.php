<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

enum MatchPolicy: string
{
    case Skip = 'skip';
    case FillMissing = 'fill_missing';
    case Overwrite = 'overwrite';
}
