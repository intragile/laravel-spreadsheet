<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Matching;

use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Matching\DatabaseRecordMatcher;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;
use IntrAgile\LaravelSpreadsheet\Matching\MatchStrength;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\PartnerMatchImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class DatabaseRecordMatcherTest extends TestCase
{
    public function test_email_rule_is_case_insensitive(): void
    {
        $partner = Partner::create(['name' => 'A', 'email' => 'regi@x.hu']);
        $import = new PartnerMatchImport([MatchRule::sure('email')]);

        $results = $import->matcher()->match($import, [2 => ['email' => 'REGI@x.hu'], 3 => ['email' => 'other@x.hu']]);

        $this->assertSame([2], array_keys($results));
        $this->assertTrue($results[2]->record->is($partner));
        $this->assertSame(MatchStrength::Sure, $results[2]->strength);
        $this->assertSame('sure|email', $results[2]->ruleId);
        $this->assertFalse($results[2]->trashed);
    }

    public function test_phone_rule_ignores_formatting_but_does_not_guess_country_codes(): void
    {
        $partner = Partner::create(['name' => 'A', 'phone' => '+36 30 111 2222']);
        $import = new PartnerMatchImport([MatchRule::sure('phone')]);

        $results = $import->matcher()->match($import, [
            2 => ['phone' => '36-30-111-2222'],
            3 => ['phone' => '06301112222'],
        ]);

        $this->assertSame([2], array_keys($results));
        $this->assertTrue($results[2]->record->is($partner));
    }

    public function test_domain_rule_matches_a_url_against_a_bare_domain(): void
    {
        $partner = Partner::create(['name' => 'A', 'domain' => 'x.hu']);
        $import = new PartnerMatchImport([MatchRule::sure('domain')]);

        $results = $import->matcher()->match($import, [2 => ['domain' => 'https://WWW.x.hu:443/']]);

        $this->assertTrue($results[2]->record->is($partner));
    }

    public function test_soft_deleted_match_is_flagged(): void
    {
        $partner = Partner::create(['name' => 'A', 'email' => 'gone@x.hu']);
        $partner->delete();
        $import = new PartnerMatchImport([MatchRule::sure('email')]);

        $results = $import->matcher()->match($import, [2 => ['email' => 'gone@x.hu']]);

        $this->assertTrue($results[2]->trashed);
    }

    public function test_suspicious_rule_and_first_rule_wins(): void
    {
        $byEmail = Partner::create(['name' => 'A', 'email' => 'a@x.hu', 'city' => 'Pécs']);
        $byName = Partner::create(['name' => 'B', 'city' => 'Pécs']);
        $import = new PartnerMatchImport([
            MatchRule::sure('email'),
            MatchRule::suspicious('name', 'city'),
        ]);

        $results = $import->matcher()->match($import, [
            2 => ['email' => 'a@x.hu', 'name' => 'B', 'city' => 'Pécs'],
            3 => ['name' => 'b', 'city' => 'pécs'],
        ]);

        $this->assertTrue($results[2]->record->is($byEmail));
        $this->assertSame(MatchStrength::Sure, $results[2]->strength);
        $this->assertTrue($results[3]->record->is($byName));
        $this->assertSame(MatchStrength::Suspicious, $results[3]->strength);
    }

    public function test_multi_field_rule_requires_the_same_record(): void
    {
        Partner::create(['name' => 'A', 'city' => 'Köln']);
        Partner::create(['name' => 'B', 'city' => 'Pécs']);
        $import = new PartnerMatchImport([MatchRule::suspicious('name', 'city')]);

        $this->assertSame([], $import->matcher()->match($import, [2 => ['name' => 'A', 'city' => 'Pécs']]));
    }

    public function test_unmatched_row_falls_through_to_the_next_rule(): void
    {
        $partner = Partner::create(['name' => 'A', 'tax_number' => '12345678-2-42']);
        $import = new PartnerMatchImport([
            MatchRule::sure('email'),
            MatchRule::suspicious('tax_number')->canonicalizeUsing('tax_number', 'tax_number'),
        ]);

        $results = $import->matcher()->match($import, [2 => ['email' => 'nowhere@x.hu', 'tax_number' => '12345678242']]);

        $this->assertTrue($results[2]->record->is($partner));
        $this->assertSame(MatchStrength::Suspicious, $results[2]->strength);
        $this->assertSame('suspicious|tax_number', $results[2]->ruleId);
    }

    public function test_duplicate_records_resolve_to_the_lowest_key(): void
    {
        $first = Partner::create(['name' => 'A', 'email' => 'd@x.hu']);
        Partner::create(['name' => 'B', 'email' => 'D@x.hu']);
        $import = new PartnerMatchImport([MatchRule::sure('email')]);

        $this->assertTrue($import->matcher()->match($import, [2 => ['email' => 'd@x.hu']])[2]->record->is($first));
    }

    public function test_blank_part_yields_no_match(): void
    {
        Partner::create(['name' => 'A', 'city' => 'Pécs']);
        $import = new PartnerMatchImport([MatchRule::suspicious('name', 'city')]);

        $this->assertSame([], $import->matcher()->match($import, [2 => ['name' => 'A', 'city' => '']]));
    }

    public function test_one_query_per_rule_per_chunk(): void
    {
        Partner::create(['name' => 'A', 'email' => 'a@x.hu']);
        $import = new PartnerMatchImport([MatchRule::sure('email'), MatchRule::sure('phone'), MatchRule::sure('domain')]);

        DB::enableQueryLog();
        $import->matcher()->match($import, [
            2 => ['email' => 'a@x.hu', 'phone' => '111', 'domain' => 'q.hu'],
            3 => ['email' => 'b@x.hu', 'phone' => '222222', 'domain' => 'r.hu'],
        ]);
        $log = DB::getQueryLog();

        $this->assertCount(3, $log);
        foreach ($log as $entry) {
            $this->assertStringContainsString(' in (', $entry['query']);
        }
    }

    public function test_large_chunks_are_split_by_lookup_batch(): void
    {
        config()->set('spreadsheet.import.lookup_batch', 500);
        Partner::create(['name' => 'A', 'email' => 'row700@x.hu']);
        $import = new PartnerMatchImport([MatchRule::sure('email')]);

        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[$i] = ['email' => "row{$i}@x.hu"];
        }

        DB::enableQueryLog();
        $results = $import->matcher()->match($import, $rows);
        $log = DB::getQueryLog();

        $this->assertCount(2, $log);
        $this->assertSame([700], array_keys($results));
        foreach ($log as $entry) {
            $this->assertLessThanOrEqual(1000, count($entry['bindings']));
        }
    }

    public function test_resolved_by_container(): void
    {
        $this->assertInstanceOf(DatabaseRecordMatcher::class, (new PartnerMatchImport([]))->matcher());
    }
}
