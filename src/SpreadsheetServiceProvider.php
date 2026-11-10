<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet;

use Illuminate\Support\ServiceProvider;
use IntrAgile\LaravelSpreadsheet\Matching\CanonicalizerRegistry;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizerRegistry;

final class SpreadsheetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spreadsheet.php', 'spreadsheet');

        $this->app->singleton(CanonicalizerRegistry::class);
        $this->app->singleton(NormalizerRegistry::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'spreadsheet');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spreadsheet.php' => $this->app->configPath('spreadsheet.php'),
            ], 'spreadsheet-config');
        }
    }
}
