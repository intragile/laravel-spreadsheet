<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Mapping;

use DateTimeImmutable;
use IntrAgile\LaravelSpreadsheet\Mapping\ValueProfile;
use IntrAgile\LaravelSpreadsheet\Mapping\ValueSniffer;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class ValueSnifferTest extends TestCase
{
    public function test_detects_email_column(): void
    {
        $profile = (new ValueSniffer)->profile(['a@b.hu', 'c@d.hu', 'e@f.hu']);

        $this->assertSame(1.0, $profile->ratio('email'));
        $this->assertTrue($profile->fits('email'));
        $this->assertSame(3, $profile->sampleCount);
    }

    public function test_detects_boolean_words(): void
    {
        $this->assertTrue((new ValueSniffer)->profile(['igen', 'nem', 'igen'])->fits('boolean'));
    }

    public function test_mixed_date_layouts_fit_date(): void
    {
        $this->assertTrue((new ValueSniffer)->profile(['2026.09.14', '2026-01-02', '14.09.2026'])->fits('date'));
    }

    public function test_native_date_cells_are_dates(): void
    {
        $profile = (new ValueSniffer)->profile([new DateTimeImmutable('2026-01-02'), new DateTimeImmutable('2026-02-03')]);

        $this->assertSame(1.0, $profile->ratio('date'));
    }

    public function test_integers_count_as_decimals(): void
    {
        $profile = (new ValueSniffer)->profile(['1', '42', '-7']);

        $this->assertTrue($profile->fits('integer'));
        $this->assertTrue($profile->fits('decimal'));
    }

    public function test_phone_numbers_fit_phone(): void
    {
        $this->assertTrue((new ValueSniffer)->profile(['06201234567', '+36301234567'])->fits('phone'));
    }

    public function test_names_exclude_other_types_by_their_own_ratio(): void
    {
        $profile = (new ValueSniffer)->profile(['Anna', 'Béla', 'Csaba', 'Dóra', 'Emese']);

        $this->assertTrue($profile->excludes('phone'));
        $this->assertTrue($profile->excludes('integer'));
        $this->assertTrue($profile->excludes('email'));
        $this->assertTrue($profile->excludes('date'));
    }

    public function test_exclusion_needs_five_samples(): void
    {
        $this->assertFalse((new ValueSniffer)->profile(['Anna', 'Béla'])->excludes('email'));
    }

    public function test_decimal_separator_is_detected(): void
    {
        $this->assertSame(',', (new ValueSniffer)->profile(['1 234,56', '99,99'])->decimalSeparator);
        $this->assertSame('.', (new ValueSniffer)->profile(['1,234.56', '99.99'])->decimalSeparator);
        $this->assertNull((new ValueSniffer)->profile(['1', '2'])->decimalSeparator);
    }

    public function test_day_above_twelve_disambiguates_slash_dates(): void
    {
        $profile = (new ValueSniffer)->profile(['04/05/2026', '25/05/2026']);

        $this->assertSame('d/m/Y', $profile->dateFormat);
        $this->assertTrue($profile->fits('date'));
        $this->assertSame('m/d/Y', (new ValueSniffer)->profile(['04/25/2026', '05/06/2026'])->dateFormat);
    }

    public function test_ambiguous_slash_dates_are_dates_without_a_format(): void
    {
        $profile = (new ValueSniffer)->profile(['04/05/2026', '06/07/2026']);

        $this->assertNull($profile->dateFormat);
        $this->assertTrue($profile->fits('date'));
    }

    public function test_blank_samples_are_skipped_and_capped_at_fifty(): void
    {
        $profile = (new ValueSniffer)->profile(['', null, '  ', ...array_fill(0, 80, 'a@b.hu')]);

        $this->assertSame(50, $profile->sampleCount);
        $this->assertSame(0, (new ValueSniffer)->profile([null, ''])->sampleCount);
    }

    public function test_profile_round_trips_through_array(): void
    {
        $profile = (new ValueSniffer)->profile(['04/05/2026', '25/05/2026']);

        $this->assertEquals($profile, ValueProfile::fromArray($profile->toArray()));
    }
}
