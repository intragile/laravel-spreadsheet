<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

enum MatchStrength: string
{
    case Sure = 'sure';
    case Suspicious = 'suspicious';
}
