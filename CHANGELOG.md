# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-10

First stable release, built on intragile/php-spreadsheet 1.0.

### Added

- Export of Eloquent queries, relations and any iterable to XLSX, ODS and CSV: fluent
  (`Export::fromQuery()`, `Export::fromRows()`, `withColumns()`) or class-based (`query()` or `rows()`
  plus `columns()`), as a download, to a filesystem disk or to a local file.
- `Column` definitions with a `data_get` path or closure, number format, width, style and text mode;
  configurable header style, sheet name and sort column.
- Keyset pagination for query exports (sort column plus table-qualified primary key,
  database-independent NULL ordering) instead of offsets; joined queries and relations such as
  `BelongsToMany` and `HasManyThrough` page correctly and read only the model table's columns; a
  joined table's column is sorted through a selected column alias.
- Queued class-based exports (`queue()`, `onConnection()`, `onQueue()`) with `ExportCompleted` and
  `ExportFailed` events.
- CSV output options: delimiter, byte-order mark and formula escaping.
- Declarative imports (`Import`, `Field`, `FieldType`) with `Import::from()` and `PendingImport`: sheet
  and header row selection (detected by default), chunk size, `run()`, `inspect()` and `queue()` with
  `ImportCompleted` and `ImportFailed` events.
- Column auto-mapping: header normalization, scoring against keys, labels and configurable synonyms,
  sample value profiling, optimal one-to-one assignment, `auto`/`review` confidence.
- Confirmation flow: `ImportStatus::NeedsConfirmation` before any row is read, immutable and
  serializable `ColumnMapping` (`with()`, `confirm()`), explicit position mappings,
  `acceptSuggestedMapping()` and strict mode (`MappingNeedsConfirmation`).
- Mapping templates found by header fingerprint (applied) or header similarity (offered), behind the
  `MappingTemplateStore` interface, with a database store and a publishable migration.
- Normalizers for dates, numbers in Hungarian and English notation, booleans, enums, countries,
  currencies, languages, emails, domains, phones and text, with locale data in the configuration and
  per-field (`Field::normalizeUsing()`) or per-type (`NormalizerRegistry::extend()`) overrides.
- Row validation with the Laravel validator, with separate rules for new and matched records.
- Failed-rows file in the upload's format with the original cells plus status and message columns,
  sensitive fields blanked and kept out of the messages; a CSV file keeps the upload's delimiter, is
  UTF-8 with a byte-order mark and escapes formulas (signed numbers excepted). It can be fixed and
  imported again with the same template.
- Configurable cell limit for XLS and ODS uploads (`import.max_cells`); safety-limit failures throw
  `ImportLimitExceeded`, which tells whether earlier chunks may already be imported.
- Record matching with sure and suspicious `MatchRule`s, built-in email, phone, domain, tax number
  and text canonicalizers, custom canonicalizers, chunked prefilter queries that stay under SQL
  Server's binding limit, in-file duplicate detection through a temporary SQLite key store, match
  policies (skip, fill missing, overwrite that keeps stored values for blank cells, callback), soft-deleted matches reported instead of
  restored, a `ChildMerger` helper for child records and a replaceable `RecordMatcher`.
- Chunked transactions with a savepoint per row, one retry of a chunk aborted by a deadlock, and
  database error messages hidden unless the application runs in debug mode.
- English and Hungarian translations.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
