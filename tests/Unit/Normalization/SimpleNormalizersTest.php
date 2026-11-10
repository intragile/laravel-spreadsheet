<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\DomainNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\EmailNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\PhoneNormalizer;
use IntrAgile\LaravelSpreadsheet\Normalization\Normalizers\TextNormalizer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class SimpleNormalizersTest extends TestCase
{
    public function test_text_and_phone_are_trimmed(): void
    {
        $context = new NormalizationContext;

        $this->assertSame('Acme Kft.', (new TextNormalizer)->normalize("  Acme Kft.\t", Field::make('n'), $context)->value);
        $this->assertSame('+36 30 123 4567', (new PhoneNormalizer)->normalize(' +36 30 123 4567 ', Field::make('p', FieldType::Phone), $context)->value);
        $this->assertSame('12', (new TextNormalizer)->normalize(12, Field::make('n'), $context)->value);
        $this->assertSame('3.5', (new TextNormalizer)->normalize(3.5, Field::make('n'), $context)->value);
    }

    public function test_email_domain_is_lower_cased(): void
    {
        $result = (new EmailNormalizer)->normalize('  John.Doe@Example.COM ', Field::make('e', FieldType::Email), new NormalizationContext);

        $this->assertSame('John.Doe@example.com', $result->value);
        $this->assertNull($result->error);
        $this->assertSame('not-an-email', (new EmailNormalizer)->normalize('not-an-email', Field::make('e', FieldType::Email), new NormalizationContext)->value);
    }

    public function test_domain_uses_the_canonicalizer(): void
    {
        $result = (new DomainNormalizer)->normalize('https://www.Example.com:8080/path?x=1', Field::make('d', FieldType::Domain), new NormalizationContext);

        $this->assertSame('example.com', $result->value);
    }

    public function test_invalid_domain_is_an_error(): void
    {
        $result = (new DomainNormalizer)->normalize('not a domain', Field::make('d', FieldType::Domain), new NormalizationContext);

        $this->assertSame('spreadsheet::messages.invalid_domain', $result->error);
        $this->assertSame(['value' => 'not a domain'], $result->errorParams);
        $this->assertNull($result->value);
    }

    public function test_blank_is_null_for_every_simple_type(): void
    {
        $context = new NormalizationContext;

        foreach ([new TextNormalizer, new EmailNormalizer, new PhoneNormalizer, new DomainNormalizer] as $normalizer) {
            foreach ([null, '', "  \t "] as $blank) {
                $result = $normalizer->normalize($blank, Field::make('x'), $context);

                $this->assertNull($result->value);
                $this->assertNull($result->error);
            }
        }
    }
}
