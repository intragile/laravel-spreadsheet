<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\BooleanNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BooleanNormalizerTest extends TestCase
{
    /** @return array<string, array{mixed, bool}> */
    public static function accepted(): array
    {
        $cases = [];

        foreach (['1', 'igen', 'yes', 'ja', 'sí', 'si', 'x', 'true', 'aktív', 'active', 'igaz', 'wahr', 'verdadero', 'IGEN', ' Yes ', 'Aktiv'] as $word) {
            $cases["true: $word"] = [$word, true];
        }

        foreach (['0', 'nem', 'no', 'nein', 'false', 'inaktív', 'inactive', 'hamis', 'falsch', 'falso', 'NEM', 'Inaktiv'] as $word) {
            $cases["false: $word"] = [$word, false];
        }

        return $cases + [
            'native true' => [true, true],
            'native false' => [false, false],
            'int one' => [1, true],
            'int zero' => [0, false],
            'float one' => [1.0, true],
        ];
    }

    #[DataProvider('accepted')]
    public function test_accepted_values(mixed $cell, bool $expected): void
    {
        $result = $this->normalize($cell);

        $this->assertNull($result->error);
        $this->assertSame($expected, $result->value);
    }

    public function test_unknown_word_is_an_error(): void
    {
        $result = $this->normalize('maybe');

        $this->assertSame('spreadsheet::messages.invalid_boolean', $result->error);
        $this->assertSame(['value' => 'maybe'], $result->errorParams);
        $this->assertNull($result->value);
        $this->assertSame('spreadsheet::messages.invalid_boolean', $this->normalize(2)->error);
    }

    public function test_blank_stays_null(): void
    {
        $this->assertNull($this->normalize(null)->value);
        $this->assertNull($this->normalize('  ')->value);
        $this->assertNull($this->normalize('  ')->error);
    }

    public function test_word_lists_come_from_config(): void
    {
        config(['spreadsheet.import.boolean.true' => ['oui'], 'spreadsheet.import.boolean.false' => ['non']]);

        $this->assertTrue($this->normalize('Oui')->value);
        $this->assertFalse($this->normalize('non')->value);
        $this->assertSame('spreadsheet::messages.invalid_boolean', $this->normalize('igen')->error);
    }

    public function test_word_tables_are_not_rebuilt_per_cell(): void
    {
        $normalizer = new BooleanNormalizer;
        $field = Field::make('b', FieldType::Boolean);
        $context = new NormalizationContext;

        $this->assertTrue($normalizer->normalize('igen', $field, $context)->value);

        $property = new \ReflectionProperty($normalizer, 'tables');
        $before = $property->getValue($normalizer);
        $normalizer->normalize('nem', $field, $context);

        $this->assertSame($before, $property->getValue($normalizer));
    }

    private function normalize(mixed $cell): NormalizedValue
    {
        return (new BooleanNormalizer)->normalize($cell, Field::make('b', FieldType::Boolean), new NormalizationContext);
    }
}
