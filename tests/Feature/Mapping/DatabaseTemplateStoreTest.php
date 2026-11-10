<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Mapping;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\DatabaseMappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplate;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\NullMappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Tests\Support\UsesFileDatabase;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;

final class DatabaseTemplateStoreTest extends TestCase
{
    use UsesFileDatabase;

    private DatabaseMappingTemplateStore $store;

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $migration = require __DIR__.'/../../../database/migrations/create_spreadsheet_mapping_templates_table.php.stub';
        $this->assertTrue(is_object($migration) && method_exists($migration, 'up'));
        $migration->up();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DatabaseMappingTemplateStore;
    }

    /** @param  list<string>  $headers */
    private function template(string $key, array $headers, string $name = '', ?string $used = null, string $fingerprint = 'fp'): MappingTemplate
    {
        return new MappingTemplate($key, $fingerprint, $name, $headers, array_fill_keys($headers, 'f'), $used === null ? null : new DateTimeImmutable($used));
    }

    public function test_exact_match_round_trips(): void
    {
        $this->store->save($this->template('partners', ['email', 'name'], 'Monthly', '2026-01-01 00:00:00'));

        $found = $this->store->findExact('partners', 'fp');

        $this->assertNotNull($found);
        $this->assertSame('Monthly', $found->name);
        $this->assertSame(['email', 'name'], $found->headers);
        $this->assertSame(['email' => 'f', 'name' => 'f'], $found->mapping);
        $this->assertSame('2026-01-01 00:00:00', $found->lastUsedAt?->format('Y-m-d H:i:s'));
        $this->assertNull($this->store->findExact('partners', 'other'));
        $this->assertNull($this->store->findExact('contacts', 'fp'));
    }

    public function test_exact_match_prefers_named_then_newest(): void
    {
        $this->store->save($this->template('partners', ['a'], '', '2026-03-01 00:00:00'));
        $this->store->save($this->template('partners', ['a'], 'Old named', '2026-01-01 00:00:00'));
        $this->store->save($this->template('partners', ['a'], 'New named', '2026-02-01 00:00:00'));
        $this->store->save($this->template('partners', ['a'], 'Never used', null));

        $this->assertSame('New named', $this->store->findExact('partners', 'fp')?->name);
    }

    public function test_newest_unnamed_wins_and_null_usage_is_oldest(): void
    {
        $this->store->save($this->template('partners', ['a'], '', null));
        $this->store->save($this->template('partners', ['a', 'b'], '', '2026-02-01 00:00:00'));

        $this->assertSame(['a', 'b'], $this->store->findExact('partners', 'fp')?->headers);
    }

    public function test_save_upserts_by_key_fingerprint_and_name(): void
    {
        $this->store->save($this->template('partners', ['a'], 'X'));
        $this->store->save(new MappingTemplate('partners', 'fp', 'X', ['a'], ['a' => 'g'], null));

        $this->assertSame(1, DB::table('spreadsheet_mapping_templates')->count());
        $this->assertSame(['a' => 'g'], $this->store->findExact('partners', 'fp')?->mapping);
    }

    public function test_similar_offers_four_of_five_but_not_three_of_four(): void
    {
        $this->store->save($this->template('partners', ['a', 'b', 'c', 'd', 'e'], fingerprint: 'five'));
        $this->assertNotNull($this->store->findSimilar('partners', ['A', 'b', 'c', 'd']));

        $this->store->save($this->template('orders', ['a', 'b', 'c', 'd'], fingerprint: 'four'));
        $this->assertNull($this->store->findSimilar('orders', ['a', 'b', 'c']));
    }

    public function test_similar_threshold_is_inclusive_and_configurable(): void
    {
        $this->store->save($this->template('partners', ['a', 'b', 'c', 'd'], fingerprint: 'one'));

        $this->assertNull($this->store->findSimilar('partners', ['a', 'b', 'c', 'x']));
        $this->assertNotNull($this->store->findSimilar('partners', ['a', 'b', 'c', 'x'], 0.6));
    }

    public function test_similar_only_looks_at_the_import_key_and_picks_the_best(): void
    {
        $this->store->save($this->template('contacts', ['a', 'b', 'c', 'd', 'e'], fingerprint: 'c'));
        $this->store->save($this->template('partners', ['a', 'b', 'c', 'd', 'e', 'f'], fingerprint: 'p1'));
        $this->store->save($this->template('partners', ['a', 'b', 'c', 'd', 'e'], fingerprint: 'p2'));

        $this->assertNull($this->store->findSimilar('orders', ['a', 'b', 'c', 'd', 'e']));
        $this->assertSame('p2', $this->store->findSimilar('partners', ['a', 'b', 'c', 'd', 'e'])?->fingerprint);
    }

    public function test_touch_updates_last_used_at(): void
    {
        $this->store->save($this->template('partners', ['a'], 'X', '2020-01-01 00:00:00'));
        $this->store->touch($this->template('partners', ['a'], 'X'));

        $this->assertGreaterThan(new DateTimeImmutable('2025-01-01'), $this->store->findExact('partners', 'fp')?->lastUsedAt);
    }

    public function test_container_binding_follows_config(): void
    {
        $this->assertInstanceOf(NullMappingTemplateStore::class, app(MappingTemplateStore::class));

        config(['spreadsheet.templates.store' => 'database']);
        app()->forgetInstance(MappingTemplateStore::class);

        $this->assertInstanceOf(DatabaseMappingTemplateStore::class, app(MappingTemplateStore::class));
    }

    public function test_null_store_remembers_nothing(): void
    {
        $store = new NullMappingTemplateStore;
        $template = $this->template('partners', ['a']);
        $store->save($template);
        $store->touch($template);

        $this->assertNull($store->findExact('partners', 'fp'));
        $this->assertNull($store->findSimilar('partners', ['a']));
    }
}
