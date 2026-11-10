<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Normalization;

/**
 * The outcome of normalizing one cell: either a value or an error, optionally with a warning.
 * Errors and warnings are translation keys (for example "spreadsheet::messages.invalid_date")
 * plus the parameters to translate them with.
 */
final readonly class NormalizedValue
{
    /**
     * @param  array<string, mixed>  $errorParams
     * @param  array<string, mixed>  $warningParams
     */
    public function __construct(
        public mixed $value = null,
        public ?string $error = null,
        public array $errorParams = [],
        public ?string $warning = null,
        public array $warningParams = [],
    ) {}

    public static function ok(mixed $value): self
    {
        return new self($value);
    }

    /** @param  array<string, mixed>  $params */
    public static function error(string $key, array $params = []): self
    {
        return new self(null, $key, $params);
    }

    /** @param  array<string, mixed>  $params */
    public function withWarning(string $key, array $params = []): self
    {
        return new self($this->value, $this->error, $this->errorParams, $key, $params);
    }
}
