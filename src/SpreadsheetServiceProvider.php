<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet;

use Illuminate\Support\ServiceProvider;
use IntrAgile\LaravelSpreadsheet\Matching\CanonicalizerRegistry;

final class SpreadsheetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spreadsheet.php', 'spreadsheet');

        $this->app->singleton(CanonicalizerRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spreadsheet.php' => $this->app->configPath('spreadsheet.php'),
            ], 'spreadsheet-config');
        }
    }
}
