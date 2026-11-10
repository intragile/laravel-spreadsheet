<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Matching;

use Illuminate\Database\Eloquent\Model;

final readonly class MatchResult
{
    public function __construct(
        public MatchStrength $strength,
        public string $ruleId,
        public Model $record,
        public bool $trashed,
    ) {}
}
