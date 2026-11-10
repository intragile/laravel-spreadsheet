<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Support;

/**
 * Points the default connection at a throw-away SQLite file instead of an in-memory database.
 */
trait UsesFileDatabase
{
    private string $databaseFile;

    protected function defineEnvironment($app): void
    {
        $this->databaseFile = sys_get_temp_dir().'/spreadsheet-test-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->databaseFile);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->databaseFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('filesystems.disks.exports', [
            'driver' => 'local',
            'root' => sys_get_temp_dir().'/spreadsheet-exports-'.bin2hex(random_bytes(4)),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->databaseFile) && is_file($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }
}
