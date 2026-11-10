<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests;

use IntrAgile\LaravelSpreadsheet\SpreadsheetServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [SpreadsheetServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Support/migrations');
    }
}
