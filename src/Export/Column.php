<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Export;

use BackedEnum;
use Closure;
use DateTimeInterface;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Style;
use Stringable;
use UnitEnum;

/**
 * One exported column: a header and the rule that turns a source row into a cell value.
 */
final class Column
{
    private ?string $numberFormat = null;

    private ?float $width = null;

    private ?Style $style = null;

    private bool $asText = false;

    /**
     * @param  string|Closure(mixed): mixed|null  $value  a data_get path, a resolver, or null to use the
     *                                                    snake_cased header as the path
     */
    private function __construct(
        private readonly string $header,
        private readonly string|Closure|null $value,
    ) {}

    /**
     * @param  string|Closure(mixed): mixed|null  $value
     */
    public static function make(string $header, string|Closure|null $value = null): self
    {
        return new self($header, $value);
    }

    /** Applies a spreadsheet number format such as "#,##0.00"; numeric strings become numbers. */
    public function format(string $numberFormat): self
    {
        $this->numberFormat = $numberFormat;

        return $this;
    }

    /** Sets the column width in characters of the default font. */
    public function width(float $chars): self
    {
        $this->width = $chars;

        return $this;
    }

    public function style(Style $style): self
    {
        $this->style = $style;

        return $this;
    }

    /** Stores the value as text, so identifiers such as "00123" never turn into numbers. */
    public function asText(): self
    {
        $this->asText = true;

        return $this;
    }

    public function header(): string
    {
        return $this->header;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    /** Resolves the cell for a source row. */
    public function cell(mixed $row): int|float|string|bool|DateTimeInterface|Cell|null
    {
        $value = $this->normalize($this->resolve($row));

        if ($this->asText) {
            return $value === null ? null : Cell::text($this->toText($value), $this->cellStyle());
        }

        $style = $this->cellStyle();

        if ($style === null || $value === null) {
            return $value;
        }

        if ($this->numberFormat !== null && is_string($value) && is_numeric($value)) {
            $value = $value + 0;
        }

        return Cell::of($value, $style);
    }

    private function resolve(mixed $row): mixed
    {
        if ($this->value instanceof Closure) {
            return ($this->value)($row);
        }

        $path = $this->value ?? $this->snake($this->header);

        return data_get($row, $path);
    }

    private function cellStyle(): ?Style
    {
        if ($this->numberFormat === null) {
            return $this->style;
        }

        return ($this->style ?? Style::make())->numberFormat($this->numberFormat);
    }

    private function toText(int|float|string|bool|DateTimeInterface $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) $value,
        };
    }

    private function snake(string $header): string
    {
        $snake = strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', trim($header)));

        return trim($snake, '_');
    }

    private function normalize(mixed $value): int|float|string|bool|DateTimeInterface|null
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable => (string) $value,
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            is_scalar($value), $value instanceof DateTimeInterface => $value,
            default => null,
        };
    }
}
