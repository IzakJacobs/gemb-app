# MBGE Access Control System — XLSX Upload/Download Deployment Guide

Same approach as the Comms Portal: SimpleXLSX (read) + SimpleXLSXGen (write),
both single-file, zero Composer dependency, work on cPanel shared hosting.

## What this adds

| File | Was | Now |
|---|---|---|
| `export.php` | CSV-only downloads (Residents, Visitors, Guards, Service Providers, Access Logs) | XLSX by default, `&format=csv` still available |
| `document_portal.php` (Levy import) | CSV/TXT only | Accepts CSV, TXT, or XLSX |
| `admin_meetings.php` (Voter Register import) | CSV only | Accepts CSV or XLSX |

A new shared library lives in `xlsx_lib/`, used by all three files.

## Step 1 — Upload files to cPanel

In `public_html/` (wherever the access system root is):

1. Create a folder `xlsx_lib/`
2. Upload into `xlsx_lib/`:
   - `SimpleXLSX.php` (reader, third-party MIT licence)
   - `SimpleXLSXGen.php` (writer, third-party MIT licence)
   - `gemb_xlsx_helper.php` (our wrapper — read/write helper functions)
3. Replace the existing files at the root with the patched versions:
   - `export.php`
   - `document_portal.php`
   - `admin_meetings.php`

⚠ Take a backup of the three existing files first (rename to `.bak` or copy aside) in case you want to roll back.

## Step 2 — Quick sanity check

After upload, log in as admin and:

1. **Export → Residents** — should download a `.xlsx` file that opens cleanly in Excel.
2. **Export → Residents, add `&format=csv`** to the URL — should still download the old-style CSV.
3. **Document Portal → Import Levy Notices** — try uploading the same data as both a `.csv` and a `.xlsx` — should behave identically.
4. **Meetings → Voter Register** — upload an `.xlsx` voter roll — should parse the same as a CSV would.

## Step 3 — Clean up

No patch scripts or test files were used this time (the access files were edited directly), so there's nothing to delete afterward.

## How the helper works (`xlsx_lib/gemb_xlsx_helper.php`)

- `gemb_read_rows($tmpPath, $ext)` — reads xlsx/csv/txt into an array of **associative** rows keyed by lower-cased header names (for code that looks up columns by name, e.g. the levy importer).
- `gemb_read_raw_rows($tmpPath, $ext)` — reads xlsx/csv/txt into an array of **indexed** rows (for code that does its own positional column detection, e.g. the voter register importer, which scans for "the column that looks like an email").
- `gemb_export($filenameNoExt, $headers, $rows, $format)` — writes either `.xlsx` (default) or `.csv` and sends it as a download, replacing the old `fputcsv`-only output in `export.php`.

This mirrors the Comms Portal's `gemb_xlsx_reader.php` pattern but adds a writer half (the Comms package only had upload/import — there was no XLSX export/download code to copy from).

## Notes

- No database changes required — this is purely upload/download handling.
- `SimpleXLSXGen` writes plain data-only `.xlsx` (no special formatting) — same level of polish as the old CSVs, just in Excel's native format so column types, accented names, and Excel-corrupting characters (commas, apostrophes) are no longer a problem.
- Max upload sizes are governed by your existing PHP `upload_max_filesize` setting — unchanged from the CSV behaviour.
