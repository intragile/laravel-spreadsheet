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
| `export-styled.xlsx` opens without repair prompt | yes | yes | yes |
| `export-styled.ods` opens | yes | yes | no (Numbers does not open ODS; the inspector failed with no document) |
| `failed-rows.xlsx` opens | yes | yes | yes |
| `failed-rows.ods` opens | yes | yes | no (as above) |
| Accents (`Árvíztűrő`, `Őrmester`, `Győr`) intact | yes, all files | yes | yes, xlsx and CSV |
| Header bold and fill `D9E1F2` (xlsx and ods) | yes (A1 bold, fill RGB 217,225,242; data rows not bold) | not inspected | not inspected |
| Money format `#,##0.00` | yes (`1.234,56`, `-42,00`) | yes (`1 234,56`) | yes (`1.234,56`) |
| Date format `yyyy-mm-dd` | yes | yes | yes |
| `00123`, `007`, `1e5` kept as text | yes | yes (`00123`) | yes in xlsx; **no in CSV** (`123`, `7`, `1E+05`, expected for CSV) |
| Formula-like text `=1+1` stays text | yes (`=1+1` shown, no formula) | not inspected | yes |
| Frozen header, xlsx | yes (split row 1) | yes (split row 1) | no header row reported (`headerRows=0`) |
| Frozen header, ods | **no** (`freeze=false`) | yes (split row 1) | not applicable |
| Autofilter | none, in all files | none reported | not applicable |
| Failed-rows status and message columns | yes (`Import status`, `Import message`, values `Error`/`Skipped`/`Needs review` and the Hungarian messages) | yes (first row) | yes (xlsx) |
| `export-hu.csv` splits into 6 columns with correct accents | **no** (see below) | **hung** (see below) | yes (6 columns, header row, accents intact) |

Column widths were written in all spreadsheet files and read back by Excel and LibreOffice.

## Findings

- **CSV in Excel:** opened through the inspector's AppleScript `open` command, `export-hu.csv` landed in a
  single column (`A1:A6`) with the semicolons unsplit. Accents were correct (the BOM works). The inspector
  does not use a double-click open, so whether a double-click splits the file under a given regional list
  separator is **unverified**.
- **CSV in LibreOffice:** the first LibreOffice run included `export-hu.csv` and produced no report within the
  inspector's 120 s limit (most likely a text-import dialog). It was killed. The CSV was left out of the
  re-run; its LibreOffice behavior is **unverified**.
- **No autofilter:** the export writer does not enable the autofilter (`SheetOptions::$autoFilter` stays
  `false`), so none of the files has one. Nothing was expected to show in the office applications.
- **ODS freeze in Excel:** Excel did not show the frozen header of the `.ods` files; LibreOffice did.
- The Hungarian `status`/`message` header texts depend on the app locale; this run used the English
  translations (`Import status`, `Import message`).
- Cells with a missing value in Excel/Numbers output (`missing value`) are empty cells (nulls), as intended.
- Not inspected: LibreOffice header bold/fill and formula-text cells, Numbers header styling. Those are
  **unverified**.

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
