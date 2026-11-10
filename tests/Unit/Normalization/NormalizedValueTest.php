<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Normalization;

use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;
use PHPUnit\Framework\TestCase;

final class NormalizedValueTest extends TestCase
{
    public function test_ok_and_error_constructors(): void
    {
        $ok = NormalizedValue::ok(5);

        $this->assertSame(5, $ok->value);
        $this->assertNull($ok->error);
        $this->assertSame([], $ok->errorParams);
        $this->assertNull($ok->warning);

        $error = NormalizedValue::error('spreadsheet::messages.unknown_value', ['value' => 'x']);

        $this->assertNull($error->value);
        $this->assertSame('spreadsheet::messages.unknown_value', $error->error);
        $this->assertSame(['value' => 'x'], $error->errorParams);
    }

    public function test_with_warning_returns_a_copy(): void
    {
        $ok = NormalizedValue::ok('HUF');
        $warned = $ok->withWarning('spreadsheet::messages.currency_from_symbol', ['value' => 'Ft']);

        $this->assertNull($ok->warning);
        $this->assertSame('HUF', $warned->value);
        $this->assertSame('spreadsheet::messages.currency_from_symbol', $warned->warning);
        $this->assertSame(['value' => 'Ft'], $warned->warningParams);
    }

    public function test_context_defaults(): void
    {
        $context = new NormalizationContext;

        $this->assertSame(['en'], $context->locales);
        $this->assertNull($context->dateFormat);
        $this->assertNull($context->decimalSeparator);
    }
}
