<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use IntrAgile\LaravelSpreadsheet\Export\KeysetCursor;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\LaravelSpreadsheet\Tests\TestCase;
use LogicException;

final class KeysetCursorTest extends TestCase
{
    private string $databaseFile;

    protected function defineEnvironment($app): void
    {
        $this->databaseFile = sys_get_temp_dir().'/keyset-cursor-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->databaseFile);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->databaseFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->databaseFile) && is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }

    public function test_iterates_by_key_when_no_sort_column(): void
    {
        $ids = $this->seedPartners(12);

        $asc = $this->ids(KeysetCursor::each(fn () => Partner::query(), null, 'asc', 5));
        $desc = $this->ids(KeysetCursor::each(fn () => Partner::query(), null, 'desc', 5));

        $this->assertSame($ids, $asc);
        $this->assertSame(array_reverse($ids), $desc);
    }

    public function test_sort_column_equal_to_key_is_treated_as_key(): void
    {
        $ids = $this->seedPartners(12);

        $viaKey = $this->ids(KeysetCursor::each(fn () => Partner::query(), 'id', 'asc', 5));

        $this->assertSame($ids, $viaKey);
    }

    public function test_nulls_and_ties_each_row_once_on_sqlite(): void
    {
        $this->seedTiesAndNulls();

        foreach (['asc', 'desc'] as $direction) {
            $rows = [];
            foreach (KeysetCursor::each(fn () => Partner::query(), 'city', $direction, 5) as $partner) {
                $rows[] = [$partner->id, $partner->city];
            }

            $this->assertCount(23, $rows);
            $this->assertCount(23, array_unique(array_column($rows, 0)), "duplicate ids ({$direction})");

            $expected = Partner::query()->whereNotNull('city')
                ->orderBy('city', $direction)->orderBy('id', $direction)->pluck('id')->all();
            $expected = array_merge(
                $expected,
                Partner::query()->whereNull('city')->orderBy('id', $direction)->pluck('id')->all(),
            );

            $this->assertSame($expected, array_column($rows, 0), "order ({$direction})");

            $nullStart = count($expected) - Partner::query()->whereNull('city')->count();
            foreach ($rows as $i => [, $city]) {
                $this->assertSame($i >= $nullStart, $city === null, "NULL rows come last ({$direction})");
            }
        }
    }

    public function test_fresh_builder_per_page(): void
    {
        $this->seedPartners(12);
        $calls = 0;

        $ids = $this->ids(KeysetCursor::each(function () use (&$calls): Builder {
            $calls++;

            return Partner::query()->where('active', true);
        }, 'name', 'asc', 5));

        $this->assertCount(12, $ids);
        // Three non-NULL pages (5 + 5 + 2) plus one probe of the NULL phase.
        $this->assertSame(4, $calls);
    }

    public function test_memory_flat(): void
    {
        $small = $this->peakGrowth(10_000);
        $large = $this->peakGrowth(100_000);

        $this->assertLessThan(8 * 1024 * 1024, $large - $small, "growth 10k={$small} 100k={$large}");
    }

    private function peakGrowth(int $rows): int
    {
        DB::table('partners')->delete();
        $now = now()->toDateTimeString();
        $batch = [];
        for ($i = 1; $i <= $rows; $i++) {
            $batch[] = ['name' => 'p'.($i % 997), 'city' => 'c'.($i % 50), 'active' => 1, 'created_at' => $now, 'updated_at' => $now];
            if (count($batch) === 1000) {
                DB::table('partners')->insert($batch);
                $batch = [];
            }
        }

        gc_collect_cycles();
        $baseline = memory_get_usage();
        $peak = $baseline;
        $count = 0;

        foreach (KeysetCursor::each(fn () => Partner::query(), 'city', 'asc', 1000) as $partner) {
            $count++;
            if ($count % 500 === 0) {
                $peak = max($peak, memory_get_usage());
            }
        }

        $this->assertSame($rows, $count);

        return $peak - $baseline;
    }

    /**
     * @param  iterable<int, Partner>  $models
     * @return list<int>
     */
    private function ids(iterable $models): array
    {
        $ids = [];
        foreach ($models as $model) {
            $ids[] = $model->id;
        }

        return $ids;
    }

    public function test_unselected_sort_column_throws_instead_of_dropping_rows(): void
    {
        $this->seedTiesAndNulls();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/"city".*selected/');

        iterator_to_array(KeysetCursor::each(fn () => Partner::query()->select('id', 'name'), 'city', 'asc', 2));
    }

    public function test_table_qualified_sort_column_throws(): void
    {
        $this->seedTiesAndNulls();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unqualified');

        iterator_to_array(KeysetCursor::each(fn () => Partner::query(), 'partners.city', 'asc', 2));
    }

    /** @return list<int> */
    private function seedPartners(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = Partner::query()->create(['name' => 'name-'.(($i * 7) % $count), 'active' => true])->id;
        }

        return $ids;
    }

    private function seedTiesAndNulls(): void
    {
        $cities = ['Pecs', null, 'Gyor', 'Pecs', null, 'Eger', 'Gyor', 'Pecs', null, 'Zalaegerszeg', 'Eger', null, 'Gyor',
            'Pecs', 'Eger', null, 'Gyor', 'Gyor', null, 'Pecs', 'Eger', 'Zalaegerszeg', null];

        foreach ($cities as $i => $city) {
            Partner::query()->create(['name' => 'n'.$i, 'city' => $city]);
        }
    }
}
