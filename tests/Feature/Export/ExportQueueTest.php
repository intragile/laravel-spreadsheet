<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use IntrAgile\LaravelSpreadsheet\Events\ExportCompleted;
use IntrAgile\LaravelSpreadsheet\Events\ExportFailed;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\LaravelSpreadsheet\Export\ExportJob;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use LogicException;

final class ExportQueueTest extends ExportTestCase
{
    public function test_queue_requires_class_based_export(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Queued exports must be a class that overrides query() or rows() and columns()');

        Export::fromQuery(Partner::query())->withColumns([Column::make('Name')])->queue('a.xlsx', 'exports');
    }

    public function test_fluent_rows_and_columns_are_rejected_too(): void
    {
        $this->expectException(LogicException::class);

        Export::fromRows([])->queue('a.xlsx');
    }

    public function test_class_based_export_is_pushed_onto_the_queue(): void
    {
        Queue::fake();

        (new PartnerNamesExport)->queue('a.xlsx', 'exports');

        Queue::assertPushed(ExportJob::class);
    }

    public function test_queued_export_fires_completed_event(): void
    {
        Storage::fake('exports');
        Event::fake([ExportCompleted::class, ExportFailed::class]);
        $this->seedPartners(5);

        (new PartnerNamesExport)->queue('out/names.xlsx', 'exports');

        Storage::disk('exports')->assertExists('out/names.xlsx');
        Event::assertDispatched(ExportCompleted::class, static fn (ExportCompleted $e): bool => $e->disk === 'exports'
            && $e->path === 'out/names.xlsx'
            && $e->rows === 5
            && $e->exportClass === PartnerNamesExport::class);
        Event::assertNotDispatched(ExportFailed::class);
    }

    public function test_parameterized_class_based_export_survives_serialization(): void
    {
        Storage::fake('exports');
        $this->seedPartners(6);

        (new MinIdExport(4))->queue('out/min.csv', 'exports');

        $this->assertSame(
            "Name\r\n\"Partner 4\"\r\n\"Partner 5\"\r\n\"Partner 6\"\r\n",
            Storage::disk('exports')->get('out/min.csv'),
        );
    }

    public function test_connection_and_queue_name_are_applied(): void
    {
        Queue::fake();

        (new PartnerNamesExport)->onConnection('redis')->onQueue('exports')->queue('a.xlsx', 'exports');

        Queue::assertPushedOn('exports', ExportJob::class, static fn (ExportJob $job): bool => $job->connection === 'redis');
    }

    public function test_failure_fires_failed_event_and_leaves_no_file(): void
    {
        Storage::fake('exports');
        Event::fake([ExportCompleted::class, ExportFailed::class]);

        try {
            (new FailingExport)->queue('out/broken.xlsx', 'exports');
            $this->fail('The failure should propagate on the sync queue.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        Storage::disk('exports')->assertMissing('out/broken.xlsx');
        Event::assertDispatched(ExportFailed::class, static fn (ExportFailed $e): bool => $e->disk === 'exports'
            && $e->path === 'out/broken.xlsx'
            && $e->message === 'boom'
            && $e->exportClass === FailingExport::class);
        Event::assertNotDispatched(ExportCompleted::class);
        $this->assertSame([], glob(sys_get_temp_dir().'/spreadsheet-export-*') ?: []);
    }

    public function test_database_failure_message_hides_sql_and_bindings(): void
    {
        config(['app.debug' => false]);
        Storage::fake('exports');
        Event::fake([ExportCompleted::class, ExportFailed::class]);

        try {
            (new BrokenQueryExport)->queue('out/broken.csv', 'exports');
            $this->fail('The failure should propagate on the sync queue.');
        } catch (QueryException) {
        }

        Event::assertDispatched(ExportFailed::class, static fn (ExportFailed $e): bool => $e->message === 'The export stopped because of a database error.');
    }
}

final class BrokenQueryExport extends Export
{
    public function query(): Builder
    {
        return Partner::query()->whereRaw('no_such_function(name) = ?', ['SECRET-991']);
    }

    public function columns(): array
    {
        return [Column::make('Name')];
    }
}

final class PartnerNamesExport extends Export
{
    public function query(): Builder
    {
        return Partner::query();
    }

    public function columns(): array
    {
        return [Column::make('Name')];
    }
}

final class FailingExport extends Export
{
    public function rows(): iterable
    {
        yield ['name' => 'ok'];

        throw new \RuntimeException('boom');
    }

    public function columns(): array
    {
        return [Column::make('Name')];
    }
}

final class MinIdExport extends Export
{
    public function __construct(private readonly int $minId) {}

    public function query(): Builder
    {
        return Partner::query()->where('id', '>=', $this->minId);
    }

    public function columns(): array
    {
        return [Column::make('Name')];
    }
}
