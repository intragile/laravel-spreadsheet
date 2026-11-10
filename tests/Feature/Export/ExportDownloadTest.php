<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Feature\Export;

use Illuminate\Database\Eloquent\Builder;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\LaravelSpreadsheet\Tests\Support\Models\Partner;
use IntrAgile\Spreadsheet\Style;
use PHPUnit\Framework\Attributes\DataProvider;

final class ExportDownloadTest extends ExportTestCase
{
    /** @return list<Column> */
    private function columns(): array
    {
        return [
            Column::make('Name'),
            Column::make('City'),
            Column::make('Revenue')->format('#,##0.00')->width(16),
        ];
    }

    public function test_columns_header_value_format_width(): void
    {
        $this->seedPartners(3);
        $file = $this->tempFile('xlsx');

        $count = Export::fromQuery(Partner::query())->withColumns($this->columns())->writeTo($file);

        $this->assertSame(3, $count);

        $rows = $this->readRows($file);
        $this->assertSame(['Name', 'City', 'Revenue'], $rows[0]);
        $this->assertSame('Partner 1', $rows[1][0]);
        $this->assertEquals(10.5, $rows[1][2]);

        $styles = $this->zipEntry($file, 'xl/styles.xml');
        $this->assertStringContainsString('#,##0.00', $styles);
        $this->assertStringContainsString('DDDDDD', $styles);
        $this->assertMatchesRegularExpression('/<b\s*\/>/', $styles);

        $sheet = $this->zipEntry($file, 'xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<col ', $sheet);
        $this->assertStringContainsString('state="frozen"', $sheet);
    }

    public function test_header_style_can_be_replaced_or_removed(): void
    {
        $file = $this->tempFile('xlsx');
        Export::fromRows([['a' => 1]])->withColumns([Column::make('A')])->headerStyle(null)->writeTo($file);
        $this->assertStringNotContainsString('DDDDDD', $this->zipEntry($file, 'xl/styles.xml'));

        $other = $this->tempFile('xlsx');
        Export::fromRows([['a' => 1]])->withColumns([Column::make('A')])->headerStyle(Style::make()->fill('FFEE00'))->writeTo($other);
        $this->assertStringContainsString('FFEE00', $this->zipEntry($other, 'xl/styles.xml'));
    }

    public function test_header_row_has_an_autofilter_in_xlsx_and_ods(): void
    {
        $xlsx = $this->tempFile('xlsx');
        Export::fromRows([['a' => 1, 'b' => 2]])->withColumns([Column::make('A'), Column::make('B')])->writeTo($xlsx);
        $this->assertMatchesRegularExpression('/<autoFilter ref="A1:B\d+"/', $this->zipEntry($xlsx, 'xl/worksheets/sheet1.xml'));

        $ods = $this->tempFile('ods');
        Export::fromRows([['a' => 1, 'b' => 2]])->withColumns([Column::make('A'), Column::make('B')])->writeTo($ods);
        $this->assertStringContainsString('table:display-filter-buttons="true"', $this->zipEntry($ods, 'content.xml'));
    }

    public function test_query_source_uses_keyset_cursor(): void
    {
        $this->seedPartners(2500, withNulls: true);
        $file = $this->tempFile('xlsx');

        $count = Export::fromQuery(Partner::query())
            ->withColumns([Column::make('Name'), Column::make('City')])
            ->sortBy('city')
            ->chunkSize(1000)
            ->writeTo($file);

        $this->assertSame(2500, $count);

        $data = array_slice($this->readRows($file), 1);
        $this->assertCount(2500, $data);
        $this->assertCount(2500, array_unique(array_map(static fn (array $r): string => is_string($r[0]) ? $r[0] : '', $data)));

        $cities = array_map(static fn (array $r) => ($r[1] ?? '') === '' ? null : $r[1], $data);
        $this->assertNotContains(null, array_slice($cities, 0, 2000));
        $this->assertSame(array_fill(0, 500, null), array_slice($cities, 2000));
    }

    public function test_relation_source(): void
    {
        $this->seedPartners(3);
        $partner = Partner::query()->firstOrFail();
        $partner->contacts()->createMany([['name' => 'A'], ['name' => 'B']]);
        Partner::query()->where('id', '!=', $partner->id)->firstOrFail()->contacts()->create(['name' => 'Other']);
        $file = $this->tempFile('csv');

        $count = Export::fromQuery($partner->contacts())->withColumns([Column::make('Name')])->writeTo($file);

        $this->assertSame(2, $count);
        $this->assertSame([['Name'], ['A'], ['B']], $this->readRows($file));
    }

    public function test_iterable_and_lazy_collection_sources(): void
    {
        $columns = [Column::make('Name', 'name')];
        $generator = (static function () {
            yield ['name' => 'a'];
            yield ['name' => 'b'];
        })();

        $first = $this->tempFile('xlsx');
        $this->assertSame(2, Export::fromRows($generator)->withColumns($columns)->writeTo($first));
        $this->assertSame([['Name'], ['a'], ['b']], $this->readRows($first));

        $second = $this->tempFile('xlsx');
        $lazy = collect([['name' => 'x'], ['name' => 'y'], ['name' => 'z']])->lazy();
        $this->assertSame(3, Export::fromRows($lazy)->withColumns($columns)->writeTo($second));
        $this->assertCount(4, $this->readRows($second));
    }

    public function test_class_based_export(): void
    {
        $this->seedPartners(4);
        $file = $this->tempFile('ods');

        $export = new class extends Export
        {
            public function query(): Builder
            {
                return Partner::query()->where('id', '>', 1);
            }

            public function columns(): array
            {
                return [Column::make('Name')];
            }
        };

        $this->assertSame(3, $export->writeTo($file));
        $this->assertSame([['Name'], ['Partner 2'], ['Partner 3'], ['Partner 4']], $this->readRows($file));
    }

    public function test_eloquent_dates_are_written_as_date_cells(): void
    {
        Partner::query()->create(['name' => 'Dated', 'founded_at' => '2020-05-06']);
        $file = $this->tempFile('xlsx');

        Export::fromQuery(Partner::query())
            ->withColumns([Column::make('Founded At'), Column::make('Created At')])
            ->writeTo($file);

        $row = $this->readRows($file)[1];
        $this->assertInstanceOf(\DateTimeImmutable::class, $row[0]);
        $this->assertSame('2020-05-06', $row[0]->format('Y-m-d'));
        $this->assertInstanceOf(\DateTimeImmutable::class, $row[1]);
    }

    public function test_missing_source_or_columns_throws(): void
    {
        $this->expectException(\LogicException::class);
        (new Export)->withColumns([Column::make('A')])->writeTo($this->tempFile('xlsx'));
    }

    public function test_missing_columns_throws(): void
    {
        $this->expectException(\LogicException::class);
        Export::fromRows([])->writeTo($this->tempFile('xlsx'));
    }

    /** @return array<string, array{string, string}> */
    public static function formats(): array
    {
        return [
            'xlsx' => ['report.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ods' => ['report.ods', 'application/vnd.oasis.opendocument.spreadsheet'],
            'csv' => ['report.csv', 'text/csv'],
        ];
    }

    /** @dataProvider formats */
    #[DataProvider('formats')]
    public function test_download_streams_and_deletes_temp_file(string $filename, string $contentType): void
    {
        $this->seedPartners(3);
        $before = glob(sys_get_temp_dir().'/spreadsheet-export-*') ?: [];

        $response = Export::fromQuery(Partner::query())->withColumns($this->columns())->download($filename);

        $this->assertStringContainsString($contentType, (string) $response->headers->get('content-type'));
        $this->assertStringContainsString($filename, (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertGreaterThan(0, (int) $response->headers->get('content-length'));

        $during = glob(sys_get_temp_dir().'/spreadsheet-export-*') ?: [];
        $this->assertCount(count($before) + 1, $during);

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        $this->assertSame((int) $response->headers->get('content-length'), strlen($body));
        $this->assertSame($before, glob(sys_get_temp_dir().'/spreadsheet-export-*') ?: []);
    }

    public function test_csv_uses_config_delimiter_bom_and_formula_escaping(): void
    {
        config([
            'spreadsheet.export.csv.delimiter' => ';',
            'spreadsheet.export.csv.bom' => true,
            'spreadsheet.export.csv.escape_formulas' => true,
        ]);
        $file = $this->tempFile('csv');

        Export::fromRows([['name' => '=HYPERLINK("http://x","y")', 'city' => 'Győr']])
            ->withColumns([Column::make('Name'), Column::make('City')])
            ->writeTo($file);

        $content = (string) file_get_contents($file);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString("Name;City\r\n", $content);
        $this->assertStringContainsString("'=HYPERLINK(", $content);
        $this->assertStringContainsString(';Győr', $content);
    }
}
