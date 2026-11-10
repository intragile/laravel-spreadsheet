Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

# Office application check

Opens the package's exports and failed-rows files in real office applications. Run it before a release.

```sh
php tests/office/build-samples.php                      # writes tests/office/out/ (git-ignored)
sh ../php-spreadsheet/tests/office/libreoffice-inspect.sh "$PWD"/tests/office/out/export-styled.xlsx ...
osascript ../php-spreadsheet/tests/office/excel-inspect.applescript <file inside ~/Library/Containers/com.microsoft.Excel/Data/>
osascript ../php-spreadsheet/tests/office/numbers-inspect.applescript <file inside ~/Library/Containers/com.apple.iWork.Numbers/Data/>
```

The inspectors are the core's (`../php-spreadsheet/tests/office/`). Excel and Numbers are sandboxed, so the
samples must be copied into their containers first. Do not pass the CSV to the LibreOffice inspector (see below).

## Samples

`export-styled.xlsx`/`.ods` (bold header with fill, `#,##0.00` money, `yyyy-mm-dd` dates, text-forced code
column, width, frozen header), `export-hu.csv` (`;` delimiter, UTF-8 BOM), `failed-rows.xlsx`/`.ods`
(original columns plus status and message columns).

## Observed results (macOS, Excel for Mac, Numbers, LibreOffice; run 2026-11-10)

| Check | Excel | LibreOffice | Numbers |
|---|---|---|---|
| `export-styled.xlsx` opened and read back (a repair prompt would block the open; none occurred within the timeout) | yes | yes | yes |
| `export-styled.ods` opens | yes | yes | no (Numbers does not open ODS; the inspector failed with no document) |
| `failed-rows.xlsx` opens | yes | yes | yes |
| `failed-rows.ods` opens | yes | yes | no (as above) |
| Accents (`Árvíztűrő`, `Őrmester`, `Győr`) intact | yes, all files | yes | yes, xlsx and CSV |
| Header bold and fill `D9E1F2` in `export-styled.xlsx` and `.ods` | yes, probed with `excel-style-probe.applescript` on both files: A1 `bold=true fill=217225242`, A2 `bold=false fill=255255255` | not inspected | not inspected |
| Failed-rows header styling | none is written; probe shows A1 `bold=false fill=255255255` in xlsx and ods | not inspected | not inspected |
| Money format `#,##0.00` | yes (`1.234,56`, `-42,00`) | yes (`1 234,56`) | yes (`1.234,56`) |
| Date format `yyyy-mm-dd` | yes | yes | yes |
| `00123`, `007`, `1e5` kept as text | yes | yes (`00123`) | yes in xlsx; **no in CSV** (`123`, `7`, `1E+05`, expected for CSV) |
| Formula-like text `=1+1` in xlsx/ods | displayed as `=1+1` (the inspector's `[=1+1]` suffix is the cell's formula text as Excel reports it); formula safety is inferred from the displayed value only | not inspected | displayed as `=1+1` |
| Frozen header, xlsx | yes (split row 1) | yes (split row 1) | no header row reported (`headerRows=0`) |
| Frozen header, ods | **no** (`freeze=false`) | yes (split row 1) | not applicable |
| Autofilter on the header row (after the writers enabled it) | yes in xlsx and ods (`autofilter=true`, all four files) | yes in xlsx and ods (`autofilter ... enabled=True`; export files `rows=1-5 columns=1-6`, failed-rows files `rows=1-4 columns=1-6`) | not inspected |
| Failed-rows status and message columns | yes (`Import status`, `Import message`, values `Error`/`Skipped`/`Needs review` and the Hungarian messages) | yes (first row) | yes (xlsx) |
| `export-hu.csv` splits into 6 columns with correct accents | **no**, also not when opened with `open -a "Microsoft Excel"` (see below) | **hung** (see below) | yes (6 columns, header row, accents intact) |

Column widths: `export-styled.*` sets explicit widths, which Excel and LibreOffice read back. The failed-rows files set no widths, so the applications show their default widths.

## Findings

- **CSV in Excel:** `export-hu.csv` (`;` delimiter, BOM) landed in a single column (`A1:A6`, A1 =
  `ID;Név;Összeg;...`) both through the inspector's AppleScript `open` and when opened like a double-click
  with `open -a "Microsoft Excel"` (machine locale hu_ES). Accents were correct, so the BOM works. Whether
  the file splits depends on Excel's list separator for the regional settings, so this is a locale-dependent
  limitation, not a defect: users can import it with Data > From Text/CSV. No `sep=` line is written because,
  by known Excel behaviour (not observed here), it disables BOM detection. Numbers split it into 6 columns correctly.
- **CSV in LibreOffice:** the first LibreOffice run included `export-hu.csv` and produced no report within the
  inspector's 120 s limit (most likely a text-import dialog). It was killed. The CSV was left out of the
  re-run; its LibreOffice behavior is **unverified**.
- **ODS in Excel and Numbers (application limitations):** Excel does not show the frozen header of `.ods`
  files (LibreOffice does), and Numbers cannot open ODS at all. Neither is a defect of the files.
- The Hungarian `status`/`message` header texts depend on the app locale; this run used the English
  translations (`Import status`, `Import message`).
- In the CSV, Excel and Numbers show `'=1+1` (apostrophe-prefixed): the writer's formula escaping, which is visible text in a CSV.
- Cells with a missing value in Excel/Numbers output (`missing value`) are empty cells (nulls), as intended.
- Not inspected: LibreOffice header bold/fill and formula-text cells, Numbers header styling. Those are
  **unverified**.

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
