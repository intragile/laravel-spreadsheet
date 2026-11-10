<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * A raw SQL fragment assembled from grammar-wrapped identifiers and constant function names.
 * Values are never interpolated; they are always passed as bindings.
 */
final class RawSql implements Expression
{
    public function __construct(private readonly string $sql) {}

    public function getValue(Grammar $grammar): string
    {
        return $this->sql;
    }
}
