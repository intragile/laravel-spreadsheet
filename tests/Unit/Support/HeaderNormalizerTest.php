<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Support;

use IntrAgile\LaravelSpreadsheet\Support\HeaderNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeaderNormalizerTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function cases(): array
    {
        return [
            ['E-mail cím', 'e mail cim'],
            ['ADÓSZÁM ', 'adoszam'],
            ['Straße/Nr.', 'strasse nr'],
            ["A\u{00A0}\tB", 'a b'],
            ['', ''],
            ['   ', ''],
            ['*** / ***', ''],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $input, string $expected): void
    {
        $this->assertSame($expected, HeaderNormalizer::normalize($input));
    }
}
