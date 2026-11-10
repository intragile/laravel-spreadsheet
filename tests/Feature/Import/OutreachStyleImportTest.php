<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Import;

use IntrAgile\LaravelSpreadsheet\Import\ImportResult;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Imports\OutreachImport;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Domain;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\DomainContact;

final class OutreachStyleImportTest extends ImportTestCase
{
    /** @param  list<list<string|null>>  $rows */
    private function import(array $rows): ImportResult
    {
        return OutreachImport::from($this->file([['domain', 'email', 'phone', 'name'], ...$rows]))->run();
    }

    public function test_outreach_style_import(): void
    {
        $result = OutreachImport::from($this->fixture('outreach.csv'))->run();

        $this->assertSame([1, 0, 1, 1], [$result->created, $result->updated, $result->skipped, $result->failed]);
        $domain = Domain::query()->sole();
        $this->assertSame('x.hu', $domain->domain);
        $contact = $domain->contacts()->sole();
        $this->assertSame(['Kiss Anna', 'info@x.hu'], [$contact->name, $contact->email]);

        $rows = $this->failedRows($result);
        $this->assertSame(['WWW.X.hu', 'Skipped', __('spreadsheet::messages.duplicate_in_file', ['row' => 2])], [$rows[1][0], $rows[1][4], $rows[1][5]]);
        $this->assertSame(['foo', 'Error'], [$rows[2][0], $rows[2][4]]);
    }

    public function test_existing_domain_gets_only_missing_contacts(): void
    {
        $domain = Domain::query()->create(['domain' => 'x.hu']);
        $domain->contacts()->create(['name' => 'Régi', 'email' => 'regi@x.hu']);

        $result = $this->import([
            ['www.x.hu', 'REGI@x.hu', null, null],
            ['x.hu:443', 'uj@x.hu', null, null],
        ]);

        $this->assertSame([0, 2, 0, 0], [$result->created, $result->updated, $result->skipped, $result->failed]);
        $this->assertSame(1, Domain::query()->count(), 'No new domain is created.');
        $this->assertEqualsCanonicalizing(['regi@x.hu', 'uj@x.hu'], $domain->contacts()->pluck('email')->all());
        $this->assertSame('x.hu', DomainContact::query()->where('email', 'uj@x.hu')->value('name'), 'A nameless contact is named after the domain.');
    }

    public function test_matching_email_fills_a_missing_phone_but_never_overwrites_one(): void
    {
        $domain = Domain::query()->create(['domain' => 'p.hu']);
        $domain->contacts()->create(['name' => 'Régi', 'email' => 'regi@p.hu']);

        $this->import([['p.hu', 'REGI@p.hu', '+36 30 111 2222', null]]);

        $contact = $domain->contacts()->sole();
        $filled = $contact->phone;
        $this->assertNotNull($filled);
        $this->assertSame('36301112222', preg_replace('/\D/', '', $filled));

        $this->import([['p.hu', 'regi@p.hu', '+36 30 999 0000', null]]);

        $this->assertSame($filled, $domain->contacts()->sole()->phone);
    }

    public function test_trashed_domain_is_never_restored_or_extended(): void
    {
        $domain = Domain::query()->create(['domain' => 'd.hu']);
        $domain->delete();

        $result = $this->import([['d.hu', 'x@d.hu', null, null]]);

        $this->assertSame(1, $result->failed);
        $this->assertTrue(Domain::withTrashed()->findOrFail($domain->id)->trashed());
        $this->assertSame(0, DomainContact::query()->count());
    }
}
