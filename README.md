# Laravel Spreadsheet

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

**Streaming XLSX, ODS and CSV export and guided import for Laravel.** Laravel developers usually pick
between a spreadsheet package that can do everything but loads the whole workbook into memory, and a
streaming one that is fast and lean but has little styling and no column mapping, validation or record
matching. This package does not make you choose: it streams rows through
[intragile/php-spreadsheet](https://github.com/intragile/php-spreadsheet), so memory stays flat as the
row count grows, and it still gives you styled exports, queued jobs and a validated import. It also does
two things the usual packages do not: **automatic column mapping** and **record matching** against the
data already in your database.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

```php
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;

return Export::fromQuery(Partner::query()->where('active', true))
    ->withColumns([
        Column::make('Name'),
        Column::make('Email'),
        Column::make('Revenue', 'revenue')->format('#,##0.00'),
    ])
    ->download('partners.xlsx');
```

```php
$result = PartnerImport::from($request->file('file')->getRealPath())->run();

// $result->created, $result->updated, $result->skipped, $result->failed, $result->failedRowsPath
```

## Contents

- [Installation](#installation)
- [Export](#export)
- [Import](#import)
- [Column mapping and confirmation](#column-mapping-and-confirmation)
- [Normalizers and locale data](#normalizers-and-locale-data)
- [Validation](#validation)
- [Failed-rows file and re-import](#failed-rows-file-and-re-import)
- [Record matching](#record-matching)
- [Queued imports, chunks and transactions](#queued-imports-chunks-and-transactions)
- [Memory](#memory)
- [Office application compatibility](#office-application-compatibility)
- [Known limitations](#known-limitations)
- [Configuration](#configuration)

## Installation

```bash
composer require intragile/laravel-spreadsheet
```

Requirements:

- PHP 8.3+ and Laravel 12 or 13.
- `ext-pdo_sqlite` (**required**): during an import, the match keys of the rows already seen are kept in
  a temporary SQLite file, so in-file duplicate detection needs no memory per row.
- `ext-intl` (**suggested**): needed for internationalized (IDN) domain names and for country and
  language names in locales other than English. Without it, accent folding falls back to a built-in
  table that covers the Latin letters of the European languages.
- The core's own requirements: `ext-zip`, `ext-xmlreader`, `ext-mbstring`, `ext-iconv`. Reading legacy
  `.xls` uploads additionally needs `phpoffice/phpspreadsheet` 5.10 or later (see the core
  README).

The service provider is discovered automatically. Publish the configuration if you want to change it:

```bash
php artisan vendor:publish --tag=spreadsheet-config
```

## Export

### Fluent exports

`Export::fromQuery()` takes an Eloquent builder or relation; `Export::fromRows()` takes any iterable
(an array, a generator, a `LazyCollection`). The format follows the file extension: `.xlsx`, `.ods` or
`.csv`.

```php
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\Spreadsheet\Style;

$export = Export::fromQuery(Order::query()->where('year', 2026))
    ->withColumns([
        Column::make('Order number', 'number')->asText(),           // "00123" stays text
        Column::make('Customer', 'customer.name'),                   // data_get path
        Column::make('Total', 'total')->format('#,##0.00')->width(14),
        Column::make('Ordered at', 'created_at')->format('yyyy-mm-dd'),
        Column::make('Status', fn (Order $order) => $order->status->label()),
    ])
    ->sortBy('created_at', 'desc')
    ->sheet('Orders')
    ->headerStyle(Style::make()->bold()->fill('D9E1F2'));

return $export->download('orders.xlsx');       // StreamedResponse
$path = $export->store('exports/orders.xlsx', 's3');
$rows = $export->writeTo('/tmp/orders.csv');  // local file, returns the row count
```

`Column::make($header, $value)`: `$value` is a `data_get` path, a closure receiving the row, or `null`, in
which case the snake_cased header is the path (`'Email'` reads `email`). Enums, `Stringable` objects,
dates and arrays (as JSON) are converted. `->format()` applies a number format, `->width()` a width in
characters, `->style()` a core `Style`, and `->asText()` stores the value as text.

Queries are read with **keyset pagination** (sort column plus primary key, `export.chunk_size` rows per
page, `->chunkSize()` to override), not with offsets, so rows inserted during the export do not shift
pages and memory does not depend on the result size. NULL sort values are emitted after the rest, the
same way on every database. The primary key is compared table-qualified, so joined queries and relations
(`BelongsToMany`, `HasManyThrough`) page correctly; a query without explicit `select()` columns reads only
the model table's columns, so a joined `id` or pivot column cannot overwrite the model's. `sortBy()` takes
a column of the model table, plain or qualified with the model's own table (a plain one is qualified
automatically when the query joins others), or a column alias of the select clause, which is compared
through the column behind it. A column of a joined table throws `LogicException`: select it under an
alias (`->addSelect('partners.name as partner_name')`) and sort by the alias. A `selectRaw()` alias is
refused too, because a raw expression cannot be compared portably.

### Class-based exports

Extend `Export` and override `query()` (or `rows()`) and `columns()`. This form can also be queued.

```php
use Illuminate\Database\Eloquent\Builder;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;

final class PartnersExport extends Export
{
    public function __construct(private readonly int $year) {}

    public function query(): Builder
    {
        return Partner::query()->whereYear('created_at', $this->year);
    }

    public function columns(): array
    {
        return [
            Column::make('Name'),
            Column::make('Tax number', 'tax_number')->asText(),
            Column::make('Revenue', 'revenue')->format('#,##0.00'),
        ];
    }
}

return (new PartnersExport(2026))->download('partners-2026.xlsx');
```

### Queued exports and events

```php
(new PartnersExport(2026))
    ->onConnection('redis')
    ->onQueue('exports')
    ->queue('exports/partners-2026.xlsx', 's3');
```

The job writes the file to the disk and fires `IntrAgile\LaravelSpreadsheet\Events\ExportCompleted`
(`disk`, `path`, `rows`, `exportClass`) or `ExportFailed` (`disk`, `path`, `exportClass`, `message`).
Only class-based exports can be queued; a fluent export throws `LogicException`, because a query
builder, a generator or a column closure cannot be serialized.

```php
use IntrAgile\LaravelSpreadsheet\Events\ExportCompleted;

Event::listen(function (ExportCompleted $event) {
    $user->notify(new ExportReady(Storage::disk($event->disk)->temporaryUrl($event->path, now()->addDay())));
});
```

### CSV for Excel

CSV output is configured under `spreadsheet.export.csv`: `delimiter` (default `,`), `bom` (default
`false`) and `escape_formulas` (default `true`: values starting with `=`, `+`, `-`, `@`, tab or carriage
return get a leading apostrophe against CSV injection). For Excel in locales that use a decimal comma,
set `'delimiter' => ';'` and `'bom' => true`. Whether Excel splits such a file into columns on
double-click depends on the list separator of the operating system's regional settings; otherwise use
**Data > From Text/CSV**. XLSX avoids the question entirely.

## Import

An import is a class that describes the target model and its fields:

```php
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;

final class PartnerImport extends Import
{
    public function model(): string
    {
        return Partner::class;
    }

    public function fields(): array
    {
        return [
            Field::make('name')->label(['en' => 'Name', 'hu' => 'Név'])->required(),
            Field::make('email', FieldType::Email)->label(['en' => 'Email', 'hu' => 'E-mail']),
            Field::make('phone', FieldType::Phone)->synonyms('mobile', 'cell'),
            Field::make('tax_number')->label('Tax number')->sensitive(),
            Field::make('revenue', FieldType::Decimal),
            Field::make('founded_at', FieldType::Date)->column('founded_on'),
            Field::make('status', FieldType::Enum)->options([
                'active' => ['en' => 'Active', 'hu' => 'Aktív'],
                'inactive' => ['en' => 'Inactive', 'hu' => 'Inaktív'],
            ]),
        ];
    }

    public function rules(): array
    {
        return ['email' => ['email'], 'revenue' => ['nullable', 'numeric', 'min:0']];
    }

    public function matchRules(): array
    {
        return [MatchRule::sure('tax_number'), MatchRule::suspicious('name')];
    }

    public function onMatch(): MatchPolicy
    {
        return MatchPolicy::FillMissing;
    }
}
```

`Field::make($key, $type)` takes one of the `FieldType` cases: `Text`, `Email`, `Phone`, `Date`,
`DateTime`, `Integer`, `Decimal`, `Boolean`, `Enum`, `Country`, `Currency`, `Language`, `Domain`. A field
is written to the model attribute of the same name unless `->column()` names another one. `->sensitive()`
blanks the column in the failed-rows file and keeps its value out of the error messages.

Run it:

```php
use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;

$result = PartnerImport::from('uploads/partners.xlsx', 'local')   // path and disk; no disk = local path
    ->sheet('Partners')        // optional: index or name; default is the first visible sheet with data
    ->headerRow(3)             // optional: detected when not given
    ->chunkSize(500)           // optional: rows per transaction
    ->run();

if ($result->status === ImportStatus::NeedsConfirmation) {
    // show $result->mapping to the user, see below
}

$result->created;          // new records
$result->updated;          // matched records written under FillMissing, Overwrite or a callback
$result->skipped;          // in-file duplicates, matches under Skip, rows sent to review
$result->failed;           // rows with an error
$result->failedRowsDisk;   // where the failed-rows file was stored (null when every row went through)
$result->failedRowsPath;
```

The import class is resolved from the container, so constructor dependencies are injected. XLSX, ODS,
CSV (encoding and delimiter detected) and, with `phpoffice/phpspreadsheet`, XLS uploads are accepted.

## Column mapping and confirmation

Without an explicit mapping, each file column is matched to a field: by a saved template with the same
header fingerprint if there is one, otherwise by the suggester. The suggester normalizes the headers
(case, accents, spaces, NBSP), scores every header against each field's key, labels and synonyms (exact
100, whole-word containment 80, word overlap 60 to 80, near spelling at least 70), adjusts the score
with a profile of the first 50 sample values (+10 when the values fit the field's type, −40 when they
rule it out), and picks the optimal one-to-one assignment (Hungarian method), not a greedy one. A match
of 90 points or more is `auto`, one from 60 up to 90 is `review`, anything lower stays unmapped.

When the mapping has a `review` match or an unmapped required field, `run()` returns
`ImportStatus::NeedsConfirmation` **before any data row is read**, with all counters at zero. A headless
flow:

```php
$pending = PartnerImport::from($path);

$mapping = $pending->inspect();           // ColumnMapping; no data row is read

foreach ($mapping->columns as $column) {  // ColumnMatch: column, header, field, score, confidence
    echo "{$column->header} -> ".($column->field ?? '-')." ({$column->confidence})\n";
}

$mapping->missingRequiredFields();        // ['name'] when no column holds the name

$confirmed = $mapping->with(3, 'phone')   // map column 3 to phone (with(3, null) clears it)
    ->confirm();

$result = PartnerImport::from($path)->mapping($confirmed)->run();
```

`ColumnMapping` is immutable and serializable (`toArray()` / `ColumnMapping::fromArray()`), so it can be
kept between the request that shows it and the one that runs the import. Other options:

- `->mapping([0 => 'name', 1 => 'email', 4 => 'phone'])` maps column positions directly; columns left out
  are not imported.
- `->acceptSuggestedMapping()` takes the suggestion including its `review` matches; unmapped required
  fields still stop the run.
- `->strict()` throws `IntrAgile\LaravelSpreadsheet\Exceptions\MappingNeedsConfirmation` instead of
  returning the status; the exception carries the mapping in `$e->mapping`.

### Mapping templates

Templates remember a confirmed mapping for files with the same headers. Enable the database store and
publish its migration:

```php
// config/spreadsheet.php
'templates' => ['store' => 'database', 'table' => 'spreadsheet_mapping_templates'],
```

```bash
php artisan vendor:publish --tag=spreadsheet-migrations
php artisan migrate
```

```php
PartnerImport::from($path)->mapping($confirmed)->saveTemplate('Monthly partner list')->run();
```

The template is saved after a completed run. A later file whose header fingerprint matches exactly gets
the template applied and confirmed automatically. A file whose header set is at least 80% similar
(Jaccard) only gets it offered as `$result->suggestedTemplate`. Templates are kept per import
(`Import::key()`, the kebab-cased class name by default) and are not separated by user or tenant: in a
multi-tenant application override `key()` to include the tenant (for example `'partners:'.tenant()->id`),
or every tenant sees the others' templates and header names. The store is the `MappingTemplateStore`
interface; bind your own implementation to keep templates elsewhere.

## Normalizers and locale data

Every cell is normalized according to its field type before validation:

| Type | Accepts | Produces |
|---|---|---|
| `Date`, `DateTime` | the formats in `import.date_formats`, slash dates, spreadsheet date cells | `CarbonImmutable` (midnight for `Date`) |
| `Integer`, `Decimal` | `1 234,56`, `1,234.56`, `1.234,56`, NBSP grouping | `int`, or a numeric string for decimals (no float rounding) |
| `Boolean` | the words in `import.boolean`, `1`/`0` | `bool` |
| `Enum` | an option value or a label in a configured locale | the option value |
| `Country` | ISO alpha-2/alpha-3 codes, English names, aliases, localized names (with `ext-intl`) | ISO 3166-1 alpha-2 |
| `Currency` | ISO 4217 codes, symbols from `import.currency_symbols`, aliases, English names | ISO 4217 code |
| `Language` | ISO 639-1/639-2 codes, English names, aliases, localized names (with `ext-intl`) | ISO 639-1 |
| `Email` | any address | trimmed, domain part lower-cased |
| `Domain` | bare domains and URLs | the host without `www.`, path or port; IDN to punycode (with `ext-intl`) |
| `Phone`, `Text` | anything | trimmed text |

Matching is case- and accent-insensitive. Ambiguous shapes are settled per column from the sample values:
a slash date such as `03/04/2026` uses the day/month order the rest of the column proves, and `1.234` uses
the column's decimal separator. When the column does not settle it, the row fails with a message instead
of being guessed. A currency recognised from a symbol carries a warning, because `$` is shared by several
currencies.

The locale data lives in the configuration, not in the code:

```php
'import' => [
    'locales' => ['hu', 'en'],                 // names accepted for countries, languages and enum labels
    'date_formats' => ['Y-m-d', 'Y.m.d.', 'd.m.Y'],
    'boolean' => ['true' => ['yes', 'igen'], 'false' => ['no', 'nem']],
    'synonyms' => ['phone' => ['hu' => ['telefonszám', 'mobil'], '*' => ['tel']]],
    'currency_symbols' => ['Ft' => 'HUF', '€' => 'EUR'],
    'aliases' => ['country' => ['holland' => 'NL'], 'currency' => ['forint' => 'HUF'], 'language' => []],
],
```

`synonyms` extends the header names the mapper tries for a field key, per locale (`*` for every locale).
To replace a normalizer for one field, pass a `Normalizer` or a closure:

```php
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;

Field::make('vat_rate', FieldType::Decimal)->normalizeUsing(
    fn (mixed $value, Field $field, NormalizationContext $context): NormalizedValue => is_numeric($value)
        ? NormalizedValue::ok((string) $value)
        : NormalizedValue::error('validation.numeric', ['attribute' => 'vat_rate']),
);
```

To replace the default of a whole type, call `app(NormalizerRegistry::class)->extend(FieldType::Phone, new MyPhoneNormalizer)`.
A field's labels, synonyms and options are available through `labels()`, `synonymList()` and
`optionMap()`.

## Validation

Normalized rows are validated with the Laravel validator, row by row. `rules()` applies to new records
and `updateRules()` (defaulting to `rules()`) to matched records, so a partial update does not have to
carry every required column. `->required()` on a field adds `required` on create. A row that fails goes
to the failed-rows file with the translated messages, and the import carries on.

## Failed-rows file and re-import

Rows that were not imported (errors, in-file duplicates, rows sent to review) are streamed into a file in
the upload's format (XLS uploads produce XLSX), with the original headers and cells plus two columns:
`Import status` (`Error`, `Skipped`, `Needs review`) and `Import message`. Sensitive fields are blanked.
The file is stored on `failed_rows.disk` under `failed_rows.directory` and only created when there is at
least one such row.

A CSV failed-rows file uses the upload's delimiter and is written as UTF-8 with a byte order mark, so a
Windows-1250 upload comes back with correct accents. Text that a spreadsheet application would run as a
formula (starting with `=`, `+`, `-`, `@`, a tab or a carriage return) gets a leading apostrophe, unless
it is a signed number or phone number: a sign followed only by digits, spaces and `. , ( ) / -` (such as
`-12,5` or `+36 1 234 5678`) cannot call a function or reference a cell and is kept as is. The apostrophe
is part of the cell text: if the user re-imports the file without removing it, it is imported too.

The user fixes the rows and uploads the same file again: the status and message columns are left out of
the header fingerprint, so a saved template still applies and the extra columns are not imported. If
your users rename those headers, list the extra texts in `failed_rows.headers`. The status and message
headers are translated (`spreadsheet::messages`, English and Hungarian are shipped).

## Record matching

`matchRules()` returns the rules that decide whether a row is a record that already exists. Every rule
lists one or more fields that together form a key; the first matching rule wins.

- `MatchRule::sure('tax_number')`: a match is the same record; the `onMatch()` policy decides what happens.
- `MatchRule::suspicious('name', 'city')`: a match might be the same record; the row goes to the
  failed-rows file as `Needs review` and nothing is written.

The same rules catch **duplicates within the file**: a row whose key a previous row of the same file
created is `Skipped` (sure rule) or `Needs review` (suspicious rule), across chunks too.

### Canonicalizers

Values are compared after canonicalization, chosen by field type or set per rule:

| Name | Comparison key | Default for |
|---|---|---|
| `email` | trimmed, lower-cased | `Email` |
| `phone` | digits only | `Phone` |
| `domain` | host without `www.`, path or port, lower-cased, IDN to punycode | `Domain` |
| `tax_number` | lower-case letters and digits only | (set it per rule) |
| `text` | accents folded, lower-cased, whitespace collapsed | every other type |

```php
MatchRule::sure('tax')->canonicalizeUsing('tax', 'tax_number');
MatchRule::sure('sku')->canonicalizeUsing('sku', new SkuCanonicalizer);

app(CanonicalizerRegistry::class)->extend('sku', new SkuCanonicalizer);   // register by name
```

A `Canonicalizer` has two methods: `canonicalize($value): ?string` and
`prefilter($query, $column, $keys, $rawValues)`. The table is never read in full: per rule and per batch
of rows, `prefilter` narrows the query with a portable condition (`LOWER`, `TRIM`, `REPLACE` only), and
the candidates are re-canonicalized in PHP and compared exactly. Every key and raw value is a query
binding, so the batches are capped at `import.lookup_batch` rows (default 500) and at 1,800 bindings per
query, which keeps each query under SQL Server's limit of 2,100 parameters. A custom canonicalizer has
to keep its own bindings within that budget.

### Policies

`onMatch()` decides what happens to a record a sure rule found:

| Policy | Effect |
|---|---|
| `MatchPolicy::Skip` (default) | nothing is written; the row counts as skipped |
| `MatchPolicy::FillMissing` | only the blank attributes of the record are filled |
| `MatchPolicy::Overwrite` | the imported values overwrite the record; a blank cell keeps the stored value |
| a closure | `fn (Model $existing, array $values): ?array` returns the attributes to write; `null` skips |

Updates are validated with `updateRules()`. Under `Overwrite` a blank cell never clears an attribute; to
clear attributes from blank cells, use a closure policy or override
`fill(Model $record, array $values, bool $exists)`, which also gives full control otherwise.

### Soft deletes

Models using `SoftDeletes` are matched including their trashed records. A row matching a trashed record
is never restored; it goes to the failed-rows file as an error ("Matches a deleted record").

### Child records

`saved(Model $record, array $values, bool $created)` runs after each record is saved, inside the row's
savepoint, so children can be merged in the same transaction. `ChildMerger` does the common case: match
an existing child by a priority list of keys, fill only its blank attributes, or add a new child.

```php
use IntrAgile\LaravelSpreadsheet\Matching\ChildMerger;

public function saved(Model $record, array $values, bool $created): void
{
    (new ChildMerger)->merge(
        $record->contacts(),
        ['name' => $values['name'] ?? null, 'email' => $values['email'] ?? null, 'phone' => $values['phone'] ?? null],
        ['email' => 'email', 'phone' => 'phone'],    // attribute => canonicalizer, highest priority first
    );
}
```

It returns `ChildMergeOutcome::Added`, `Filled` or `Unchanged`. Several rows of one file may update the
same existing record, so a file with one row per contact merges all contacts into one parent.

### Custom matcher

The default `DatabaseRecordMatcher` compares model attributes. To match through accessors, relations or
an external index, override `matcher()` and return your own `RecordMatcher`; its
`match(Import $import, array $valuesByRow): array` receives one chunk and returns a `MatchResult` per
matched row number.

## Queued imports, chunks and transactions

```php
PartnerImport::from('uploads/partners.xlsx', 's3')
    ->mapping($confirmed)
    ->onConnection('redis')
    ->onQueue('imports')
    ->queue();
```

The job fires `IntrAgile\LaravelSpreadsheet\Events\ImportCompleted` (`result`, `importClass`) or
`ImportFailed` (`importClass`, `message`). The import object is serialized with the job, so keep
closures out of its properties (build them inside `onMatch()` instead). The worker must be able to read the file, so queued imports
normally read from a shared disk. A mapping that needs confirmation completes with
`ImportStatus::NeedsConfirmation`; with `->strict()` it fails instead. Database error messages are
replaced by a generic translated text unless `app.debug` is on.

Rows are written in one transaction per chunk (`import.chunk_size`, default 500) with a savepoint per row,
so a failing row rolls back alone and becomes an error row. When the database aborts a chunk with a
deadlock or another concurrency error, the chunk is retried once from scratch; a second such error fails
the import.

The import job runs once (`$tries = 1`). Set the worker's `--timeout` (or the job's `$timeout`) and the
queue connection's `retry_after` above the longest import you expect; otherwise the worker kills the job
mid-import, or the queue hands it to a second worker while the first is still running. A job killed
mid-import leaves the chunks it had committed in the database and rolls back only the chunk in progress;
no failed-rows file is stored, and its temporary files stay in the system temp directory. Running the same file again is safe when the import has a sure match rule:
the committed rows then match their records instead of being created twice.

## Memory

Neither export nor import memory grows with the row count. The test suite checks it: each memory test
measures the peak memory growth at N and at 10×N rows and fails if the difference reaches 8 MB.

- **Import** (`ImportMemoryTest`, CSV with 10,000 and 100,000 rows, half of them invalid so the
  failed-rows file is written too): peak growth was **1,036,064 bytes at 10k rows** and **1,048,040
  bytes at 100k rows**, about 12 KB apart.
- **Export** (`ExportMemoryTest`, 10,000 and 100,000 rows from a file-backed SQLite table through keyset
  pagination to XLSX): asserted under the same 8 MB rule.

The in-file duplicate keys live in a temporary SQLite file, not in an array. For the core's own numbers
and benchmarks, see the [php-spreadsheet README](https://github.com/intragile/php-spreadsheet).

## Office application compatibility

Exports and failed-rows files were opened in Excel for Mac, LibreOffice and Numbers on macOS before the
release (full results in [tests/office/README.md](tests/office/README.md)):

- XLSX opened in all three with no repair prompt; accents, `#,##0.00` and `yyyy-mm-dd` formats and
  text-forced codes (`00123`) came through in all three.
- The frozen header row of XLSX shows in Excel and LibreOffice (Numbers reported none); the header
  autofilter is set in Excel and LibreOffice, for XLSX and ODS alike.
- The bold, filled header was confirmed in Excel for XLSX and ODS (not inspected in the other two).
- ODS opened in Excel and LibreOffice; Numbers cannot open ODS.
- The `;`-delimited Hungarian CSV split into columns in Numbers; in Excel it did not under the test
  machine's regional settings (see below), and in LibreOffice it was not verified (the text-import dialog
  blocked the automated check).

## Known limitations

- **CSV with `;` in Excel:** whether Excel splits a `;`-delimited CSV into columns on double-click depends
  on the operating system's list separator. Otherwise import it with **Data > From Text/CSV**. Numbers
  splits it correctly. CSV carries no cell types, so an application may turn `00123` into a number
  (Numbers did); use XLSX when codes must stay text.
- **ODS:** Excel ignores the frozen header of ODS files and Numbers cannot open ODS at all. Both are
  application limits; LibreOffice shows both correctly.
- **Queued imports are serialized:** the job serializes the import object, so an import class that holds
  a closure (or any other unserializable value) in a property cannot be queued. A closure policy built
  inside `onMatch()` is fine, because it is created on the worker.
- **Deadlock retry:** the chunk is retried once, and only usefully when the importer owns the outermost
  transaction. Inside a transaction your code opened, the retry would run in a transaction the database
  has already aborted.
- **Record-matching prefilter:** bindings are chunked to stay under SQL Server's 2,100-parameter limit;
  custom canonicalizers must respect the same budget.
- **XLS uploads** are read whole into memory by `phpoffice/phpspreadsheet`, once per pass: inspection,
  header and sample scan, and the import itself. The cell count is checked first against
  `import.max_cells` (default 2,000,000), so a larger XLS file fails before anything is imported. The
  same setting caps how far ODS repeated cells and rows may expand; ODS is streamed, so that limit can
  be reached during the import, after earlier chunks were committed, and the message then says so.
  Both throw `IntrAgile\LaravelSpreadsheet\Exceptions\ImportLimitExceeded` (a core `LimitExceeded`;
  `$partial` tells whether rows may have been imported). Every other format streams.
- `ext-pdo_sqlite` is required; without `ext-intl`, IDN domains and non-English country and language
  names are not recognised and accent folding uses the built-in table.

## Configuration

`config/spreadsheet.php` (publish with `--tag=spreadsheet-config`):

| Key | Default | Meaning |
|---|---|---|
| `export.chunk_size` | 1000 | rows per keyset page |
| `export.header_style` | bold, fill `DDDDDD` | header style: `bold`, `italic`, `wrap`, `fill`, `font_color`, `align`, `border` |
| `export.csv.delimiter` / `bom` / `escape_formulas` | `,` / `false` / `true` | CSV output |
| `import.chunk_size` | 500 | rows per transaction |
| `import.lookup_batch` | 500 | rows per existing-record lookup query |
| `import.max_cells` | 2,000,000 | most cells of an XLS workbook (or expanded ODS sheet) accepted |
| `import.locales` | `['en']` | locales whose names are accepted |
| `import.date_formats` | ISO and Hungarian/German layouts | unambiguous date layouts, tried in order |
| `import.boolean` | en, hu, de, es words | words read as true and false |
| `import.synonyms` | common fields in en, hu, de, es | extra header names per field key |
| `import.currency_symbols` | `Ft`, `€`, `$`, `£` | symbol to ISO 4217 |
| `import.aliases` | country and currency aliases | extra names per country, currency, language |
| `failed_rows.disk` / `directory` | default disk / `spreadsheet/failed-rows` | where failed-rows files go |
| `failed_rows.headers` | `[]` | extra status and message header texts |
| `templates.store` / `table` | `null` / `spreadsheet_mapping_templates` | `'database'` enables the template store |

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
