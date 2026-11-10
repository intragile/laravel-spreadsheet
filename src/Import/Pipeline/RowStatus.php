<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Import\Pipeline;

/** Why a source row ended up in the failed-rows file. */
enum RowStatus: string
{
    case Error = 'error';
    case Skipped = 'skipped';
    case Review = 'review';

    public function label(): string
    {
        return (string) __('spreadsheet::messages.status.'.$this->value);
    }
}
