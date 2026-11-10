<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

/**
 * What a column's sample values look like: for each recognised value type the share of
 * samples that parse as that type, plus the date layout and decimal separator the column
 * appears to use.
 */
final readonly class ValueProfile
{
    public const TYPES = ['email', 'phone', 'integer', 'decimal', 'boolean', 'date'];

    private const FIT_RATIO = 0.8;

    private const EXCLUDE_RATIO = 0.1;

    private const EXCLUDE_MIN_SAMPLES = 5;

    /** @param  array<string, float>  $ratios  Value type => share of samples (0..1). */
    public function __construct(
        public array $ratios = [],
        public int $sampleCount = 0,
        public ?string $dateFormat = null,
        public ?string $decimalSeparator = null,
    ) {}

    public function ratio(string $type): float
    {
        return $this->ratios[$type] ?? 0.0;
    }

    /** The column holds values of this type: at least 80 % of the samples parse as it. */
    public function fits(string $type): bool
    {
        return $this->sampleCount > 0 && $this->ratio($type) >= self::FIT_RATIO;
    }

    /** The column is clearly not this type: under 10 % of at least five samples parse as it. */
    public function excludes(string $type): bool
    {
        return $this->sampleCount >= self::EXCLUDE_MIN_SAMPLES && $this->ratio($type) < self::EXCLUDE_RATIO;
    }

    /** @return array{ratios: array<string, float>, sample_count: int, date_format: ?string, decimal_separator: ?string} */
    public function toArray(): array
    {
        return [
            'ratios' => $this->ratios,
            'sample_count' => $this->sampleCount,
            'date_format' => $this->dateFormat,
            'decimal_separator' => $this->decimalSeparator,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $ratios = [];

        foreach (is_array($data['ratios'] ?? null) ? $data['ratios'] : [] as $type => $ratio) {
            $ratios[(string) $type] = is_numeric($ratio) ? (float) $ratio : 0.0;
        }

        return new self(
            $ratios,
            is_int($data['sample_count'] ?? null) ? $data['sample_count'] : 0,
            is_string($data['date_format'] ?? null) ? $data['date_format'] : null,
            is_string($data['decimal_separator'] ?? null) ? $data['decimal_separator'] : null,
        );
    }
}
