<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet;

use Illuminate\Support\ServiceProvider;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\DatabaseMappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\MappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Mapping\Templates\NullMappingTemplateStore;
use IntrAgile\LaravelSpreadsheet\Matching\CanonicalizerRegistry;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizerRegistry;

final class SpreadsheetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spreadsheet.php', 'spreadsheet');

        $this->app->singleton(CanonicalizerRegistry::class);
        $this->app->singleton(NormalizerRegistry::class);
        $this->app->singleton(MappingTemplateStore::class, fn (): MappingTemplateStore => config('spreadsheet.templates.store') === 'database'
            ? new DatabaseMappingTemplateStore
            : new NullMappingTemplateStore);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'spreadsheet');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spreadsheet.php' => $this->app->configPath('spreadsheet.php'),
            ], 'spreadsheet-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_spreadsheet_mapping_templates_table.php.stub' => $this->app->databasePath(
                    'migrations/'.date('Y_m_d_His').'_create_spreadsheet_mapping_templates_table.php'
                ),
            ], 'spreadsheet-migrations');
        }
    }
}
