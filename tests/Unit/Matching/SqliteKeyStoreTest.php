<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Matching;

use IntrAgile\LaravelSpreadsheet\Matching\SqliteKeyStore;
use PHPUnit\Framework\TestCase;

final class SqliteKeyStoreTest extends TestCase
{
    public function test_first_sight_is_null_and_repeat_returns_first_row(): void
    {
        $store = new SqliteKeyStore;

        $this->assertNull($store->firstSeen('r', 'k', 2));
        $this->assertSame(2, $store->firstSeen('r', 'k', 9));
        $this->assertSame(2, $store->firstSeen('r', 'k', 10));

        $store->close();
    }

    public function test_seen_looks_up_without_recording(): void
    {
        $store = new SqliteKeyStore;

        $this->assertNull($store->seen('r', 'k'));
        $this->assertNull($store->firstSeen('r', 'k', 4), 'A lookup does not record the key.');
        $this->assertSame(4, $store->seen('r', 'k'));
        $this->assertNull($store->seen('other', 'k'));

        $store->close();
    }

    public function test_rules_do_not_collide(): void
    {
        $store = new SqliteKeyStore;

        $this->assertNull($store->firstSeen('a', 'k', 1));
        $this->assertNull($store->firstSeen('b', 'k', 2));
        $this->assertSame(2, $store->firstSeen('b', 'k', 3));

        $store->close();
    }

    public function test_close_removes_the_temp_file(): void
    {
        $store = new SqliteKeyStore;
        $store->firstSeen('r', 'k', 1);
        $path = $store->path();

        $this->assertFileExists($path);
        $store->close();
        $this->assertFileDoesNotExist($path);
    }

    public function test_memory_stays_flat_for_many_keys(): void
    {
        $store = new SqliteKeyStore;

        for ($i = 0; $i < 10_000; $i++) {
            $store->firstSeen('r', sha1((string) $i), $i);
        }

        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 10_000; $i < 300_000; $i++) {
            $store->firstSeen('r', sha1((string) $i), $i);
        }

        $growth = memory_get_usage() - $before;
        $store->close();

        $this->assertLessThan(8 * 1024 * 1024, $growth);
    }
}
