<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

/** The suggested field for one file column, with how sure the suggester is. */
final readonly class ColumnMatch
{
    public const AUTO = 'auto';

    public const REVIEW = 'review';

    public const NONE = 'none';

    /** @param  'auto'|'review'|'none'  $confidence */
    public function __construct(
        public int $column,
        public string $header,
        public ?string $field,
        public float $score,
        public string $confidence,
        public ValueProfile $profile,
    ) {}

    /** @return array{column: int, header: string, field: ?string, score: float, confidence: string, profile: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'header' => $this->header,
            'field' => $this->field,
            'score' => $this->score,
            'confidence' => $this->confidence,
            'profile' => $this->profile->toArray(),
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $confidence = $data['confidence'] ?? self::NONE;

        return new self(
            is_int($data['column'] ?? null) ? $data['column'] : 0,
            is_string($data['header'] ?? null) ? $data['header'] : '',
            is_string($data['field'] ?? null) ? $data['field'] : null,
            is_numeric($data['score'] ?? null) ? (float) $data['score'] : 0.0,
            in_array($confidence, [self::AUTO, self::REVIEW], true) ? $confidence : self::NONE,
            ValueProfile::fromArray(is_array($data['profile'] ?? null) ? $data['profile'] : []),
        );
    }
}
