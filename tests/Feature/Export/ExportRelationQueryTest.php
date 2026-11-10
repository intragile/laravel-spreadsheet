<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Export;

use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Contact;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Domain;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\DomainContact;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Tag;

final class ExportRelationQueryTest extends ExportTestCase
{
    public function test_joined_query_pages_by_the_qualified_key(): void
    {
        $acme = Partner::query()->create(['name' => 'Acme']);
        $beta = Partner::query()->create(['name' => 'Beta']);
        $expected = [];

        foreach (['C1' => $acme, 'C2' => $beta, 'C3' => $acme, 'C4' => $beta, 'C5' => $acme] as $name => $partner) {
            $expected[] = [(string) Contact::query()->create(['name' => $name, 'partner_id' => $partner->id])->id, $name, $partner->name];
        }

        $query = Contact::query()
            ->join('partners', 'partners.id', '=', 'contacts.partner_id')
            ->select('contacts.*', 'partners.name as partner_name');

        $path = $this->tempFile('csv');
        $count = Export::fromQuery($query)->chunkSize(2)
            ->withColumns([Column::make('Id', 'id'), Column::make('Name', 'name'), Column::make('Partner', 'partner_name')])
            ->writeTo($path);

        $this->assertSame(5, $count);
        $this->assertSame($expected, array_slice($this->readRows($path), 1));
    }

    public function test_joined_query_sorted_by_an_unqualified_model_column(): void
    {
        $acme = Partner::query()->create(['name' => 'Acme']);

        foreach (['Delta', 'Alpha', 'Charlie', 'Bravo', 'Echo'] as $name) {
            Contact::query()->create(['name' => $name, 'partner_id' => $acme->id]);
        }

        $query = Contact::query()->join('partners', 'partners.id', '=', 'contacts.partner_id');

        $path = $this->tempFile('csv');
        Export::fromQuery($query)->chunkSize(2)->sortBy('name')
            ->withColumns([Column::make('Name', 'name')])
            ->writeTo($path);

        $this->assertSame([['Alpha'], ['Bravo'], ['Charlie'], ['Delta'], ['Echo']], array_slice($this->readRows($path), 1));
    }

    public function test_belongs_to_many_exports_the_related_models_not_the_pivot_rows(): void
    {
        $acme = Partner::query()->create(['name' => 'Acme']);
        $other = Partner::query()->create(['name' => 'Other']);
        $tags = [];

        foreach (['red', 'green', 'blue', 'cyan', 'pink'] as $name) {
            $tags[] = Tag::query()->create(['name' => $name]);
        }

        // Pivot rows of another partner first, so pivot ids differ from tag ids.
        foreach ($tags as $tag) {
            DB::table('partner_tag')->insert(['partner_id' => $other->id, 'tag_id' => $tag->id, 'name' => 'pivot']);
        }

        foreach (array_reverse($tags) as $tag) {
            DB::table('partner_tag')->insert(['partner_id' => $acme->id, 'tag_id' => $tag->id, 'name' => 'pivot']);
        }

        $path = $this->tempFile('csv');
        $count = Export::fromQuery($acme->tags())->chunkSize(2)
            ->withColumns([Column::make('Id', 'id'), Column::make('Name', 'name')])
            ->writeTo($path);

        $this->assertSame(5, $count);
        $this->assertSame(
            array_map(static fn (Tag $tag): array => [(string) $tag->id, $tag->name], $tags),
            array_slice($this->readRows($path), 1),
        );
    }

    public function test_has_many_through_pages_over_several_chunks(): void
    {
        $acme = Partner::query()->create(['name' => 'Acme']);
        $expected = [];

        foreach (['a.hu', 'b.hu', 'c.hu'] as $name) {
            $domain = Domain::query()->create(['domain' => $name, 'partner_id' => $acme->id]);

            foreach ([1, 2] as $i) {
                $contact = DomainContact::query()->create(['domain_id' => $domain->id, 'name' => $name.'-'.$i]);
                $expected[] = [(string) $contact->id, $contact->name];
            }
        }

        $path = $this->tempFile('csv');
        $count = Export::fromQuery($acme->domainContacts())->chunkSize(4)
            ->withColumns([Column::make('Id', 'id'), Column::make('Name', 'name')])
            ->writeTo($path);

        $this->assertSame(6, $count);
        $this->assertSame($expected, array_slice($this->readRows($path), 1));
    }
}
