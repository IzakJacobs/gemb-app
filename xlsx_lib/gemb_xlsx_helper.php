<?php
/**
 * GEMB Access Control System
 * gemb_xlsx_helper.php — shared xlsx read/write helper
 *
 * Mirrors the GEMB Communications Portal xlsx pattern:
 *   - SimpleXLSX.php     (read .xlsx)   — Shuchkin\SimpleXLSX
 *   - SimpleXLSXGen.php  (write .xlsx)  — Shuchkin\SimpleXLSXGen
 * Zero Composer dependency. Drop both files in xlsx_lib/ alongside this one.
 *
 * READ:
 *   $rows = gemb_read_rows($_FILES['csv']['tmp_name'], $ext); // $ext = 'xlsx'|'csv'|'txt'
 *   // Returns array of associative arrays keyed by lower-cased header names,
 *   // identical shape to the existing fgetcsv()+array_combine() logic in document_portal.php.
 *
 * WRITE (download):
 *   gemb_export(sprintf('GEMB_Residents_%s', date('Ymd')), ['Erf No','Name', ...], $rows, $format);
 *   // $format = 'xlsx' (default) or 'csv'. Sends headers + file content and exits.
 */

require_once __DIR__ . '/SimpleXLSX.php';
require_once __DIR__ . '/SimpleXLSXGen.php';

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

// ---------------------------------------------------------------------------
// READ — xlsx or csv/txt upload -> array of associative rows
// ---------------------------------------------------------------------------

/**
 * @param  string $tmpPath   uploaded tmp file path
 * @param  string $extension 'xlsx', 'csv', or 'txt'
 * @return array  list of associative arrays, keys = lower-cased trimmed headers
 */
function gemb_read_rows(string $tmpPath, string $extension): array
{
    $extension = strtolower($extension);
    if ($extension === 'xlsx') {
        return gemb_read_rows_xlsx($tmpPath);
    }
    return gemb_read_rows_csv($tmpPath);
}

function gemb_read_rows_xlsx(string $tmpPath): array
{
    if (!class_exists('Shuchkin\SimpleXLSX')) {
        return [];
    }
    $xlsx = SimpleXLSX::parse($tmpPath);
    if (!$xlsx) {
        return [];
    }
    $raw = $xlsx->rows(0);
    if (empty($raw)) {
        return [];
    }

    $header = array_map(function ($h) {
        return strtolower(trim((string)$h));
    }, $raw[0]);

    $rows = [];
    for ($i = 1; $i < count($raw); $i++) {
        $row = $raw[$i];
        if (count($row) < 1 || implode('', array_map('strval', $row)) === '') {
            continue; // skip empty rows
        }
        $rows[] = array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row, 0, count($header))
        );
    }
    return $rows;
}

function gemb_read_rows_csv(string $tmpPath): array
{
    $rows = [];
    $fh = fopen($tmpPath, 'r');
    if (!$fh) {
        return [];
    }

    $header = null;
    // Auto-detect delimiter from first line (tab, semicolon, or comma)
    $firstLine = fgets($fh);
    rewind($fh);
    $firstLine = ltrim((string)$firstLine, "\xEF\xBB\xBF");
    $tabs   = substr_count($firstLine, "\t");
    $semis  = substr_count($firstLine, ';');
    $commas = substr_count($firstLine, ',');
    if ($tabs >= $semis && $tabs >= $commas)  $delim = "\t";
    elseif ($semis > $commas)                 $delim = ';';
    else                                       $delim = ',';

    while (($row = fgetcsv($fh, 2000, $delim)) !== false) {
        if (!$header) {
            $header = array_map(function ($h) {
                return strtolower(trim(ltrim((string)$h, "\xEF\xBB\xBF")));
            }, $row);
            continue;
        }
        if (count($row) < 1 || implode('', $row) === '') {
            continue;
        }
        $rows[] = array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row, 0, count($header))
        );
    }
    fclose($fh);
    return $rows;
}

// ---------------------------------------------------------------------------
// READ (raw) — xlsx or csv upload -> array of indexed (non-keyed) row arrays
// Use this when the caller does its own positional column detection
// (e.g. "find the email column, everything before it is the name").
// ---------------------------------------------------------------------------

/**
 * @param  string $tmpPath   uploaded tmp file path
 * @param  string $extension 'xlsx', 'csv', or 'txt'
 * @return array  list of indexed arrays, one per row (no header row skipped)
 */
function gemb_read_raw_rows(string $tmpPath, string $extension): array
{
    $extension = strtolower($extension);
    if ($extension === 'xlsx') {
        if (!class_exists('Shuchkin\SimpleXLSX')) {
            return [];
        }
        $xlsx = SimpleXLSX::parse($tmpPath);
        if (!$xlsx) {
            return [];
        }
        $raw = $xlsx->rows(0);
        // Normalise cell values to strings/trim, matching fgetcsv() output shape
        return array_map(function ($row) {
            return array_map(function ($cell) {
                return trim((string)$cell);
            }, $row);
        }, $raw ?: []);
    }

    // csv / txt
    $rows = [];
    $fh = fopen($tmpPath, 'r');
    if (!$fh) {
        return [];
    }
    while (($row = fgetcsv($fh)) !== false) {
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

// ---------------------------------------------------------------------------
// WRITE — array of rows -> xlsx or csv download
// ---------------------------------------------------------------------------

/**
 * @param string $filenameNoExt filename without extension, e.g. 'GEMB_Residents_20260630'
 * @param array  $headers       column headers (first row)
 * @param array  $rows          list of associative or indexed arrays
 * @param string $format        'xlsx' (default) or 'csv'
 */
function gemb_export(string $filenameNoExt, array $headers, array $rows, string $format = 'xlsx'): void
{
    $format = strtolower($format) === 'csv' ? 'csv' : 'xlsx';

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filenameNoExt . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        fclose($out);
        exit;
    }

    // xlsx
    $sheet = [$headers];
    foreach ($rows as $row) {
        $sheet[] = array_values($row);
    }
    SimpleXLSXGen::fromArray($sheet)->downloadAs($filenameNoExt . '.xlsx');
    exit;
}
