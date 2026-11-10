<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Mapping;

use IntrAgile\LaravelSpreadsheet\Mapping\HeaderFingerprint;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class HeaderFingerprintTest extends TestCase
{
    public function test_order_and_formatting_do_not_matter(): void
    {
        $this->assertSame(
            HeaderFingerprint::of(['Email', 'Név'], []),
            HeaderFingerprint::of(['  nev ', 'EMAIL'], []),
        );
    }

    public function test_empty_headers_are_ignored(): void
    {
        $this->assertSame(HeaderFingerprint::of(['Email'], []), HeaderFingerprint::of(['Email', '', ' '], []));
    }

    public function test_duplicates_change_the_fingerprint(): void
    {
        $this->assertNotSame(HeaderFingerprint::of(['Email', 'Email'], []), HeaderFingerprint::of(['Email'], []));
    }

    public function test_failed_rows_headers_of_every_locale_are_ignored(): void
    {
        $ignored = HeaderFingerprint::ignoredHeaders();
        $base = HeaderFingerprint::of(['Email', 'Name'], $ignored);

        $this->assertSame($base, HeaderFingerprint::of(['Email', 'Name', 'Import status', 'Import message'], $ignored));
        $this->assertSame($base, HeaderFingerprint::of(['Email', 'Name', 'Import státusz', 'Import üzenet'], $ignored));
    }

    public function test_configured_headers_are_ignored(): void
    {
        config(['spreadsheet.failed_rows.headers' => ['Fehler']]);

        $this->assertContains('fehler', HeaderFingerprint::ignoredHeaders());
    }

    public function test_user_column_named_reason_is_kept(): void
    {
        $ignored = HeaderFingerprint::ignoredHeaders();

        $this->assertNotContains('reason', $ignored);
        $this->assertNotSame(HeaderFingerprint::of(['Email'], $ignored), HeaderFingerprint::of(['Email', 'Reason'], $ignored));
    }
}
