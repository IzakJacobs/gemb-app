<?php
// ============================================================
// GEMB Communications Portal — gemb_file_reader.php
//
// Shared import file reader for the comms module.
// Accepts .csv, .txt, and .xlsx uploads and returns an
// identical array structure regardless of source format.
//
// Return value (both formats):
//   Array of associative arrays, one per data row.
//   Keys are lowercase trimmed column header names.
//   If no header row exists, keys are '0', '1', '2' etc.
//
// Usage:
//   require_once __DIR__ . '/gemb_file_reader.php';
//   $result = gembReadImportFile('/tmp/upload_xyz');
//   if (!$result['ok']) { echo $result['error']; }
//   else { $rows = $result['rows']; }
//
// Supported extensions: csv, txt, xlsx
// Max file size: enforced by PHP upload_max_filesize (server)
// ============================================================

// ── Load SimpleXLSX only when needed (lazy) ─────────────────
function gembLoadSimpleXLSX(): bool {
    if (class_exists('Shuchkin\SimpleXLSX')) return true;
    $path = __DIR__ . '/SimpleXLSX.php';
    if (!file_exists($path)) return false;
    require_once $path;
    return class_exists('Shuchkin\SimpleXLSX');
}

// ── Main entry point ─────────────────────────────────────────
// $tmpPath   — path to the uploaded temp file ($_FILES[x]['tmp_name'])
// $extension — original file extension, lowercase ('csv','txt','xlsx')
//
// Returns:
//   ['ok' => true,  'rows' => [...], 'format' => 'csv'|'xlsx']
//   ['ok' => false, 'error' => 'Human-readable message']
//
function gembReadImportFile(string $tmpPath, string $extension): array {
    $ext = strtolower(trim($extension, '. '));

    if ($ext === 'xlsx') {
        return gembReadXlsx($tmpPath);
    }

    if (in_array($ext, ['csv', 'txt'], true)) {
        return gembReadCsv($tmpPath);
    }

    return ['ok' => false, 'error' => 'Unsupported file type: .' . $ext . '. Please upload a .csv, .txt, or .xlsx file.'];
}

// ── CSV / TXT reader ─────────────────────────────────────────
// Handles BOM, auto-detects delimiter (comma / semicolon / tab).
// Returns associative rows keyed by lowercase header names,
// or numeric keys if no header row.
//
function gembReadCsv(string $tmpPath): array {
    $fh = @fopen($tmpPath, 'r');
    if ($fh === false) {
        return ['ok' => false, 'error' => 'Could not open the uploaded file for reading.'];
    }

    // Read first line to detect BOM and delimiter
    $firstLine = fgets($fh);
    if ($firstLine === false) {
        fclose($fh);
        return ['ok' => false, 'error' => 'The uploaded file appears to be empty.'];
    }
    rewind($fh);

    // Strip UTF-8 BOM if present
    $firstLine = ltrim($firstLine, "\xEF\xBB\xBF");

    // Detect delimiter
    $tabs   = substr_count($firstLine, "\t");
    $semis  = substr_count($firstLine, ';');
    $commas = substr_count($firstLine, ',');

    if ($tabs >= $semis && $tabs >= $commas)  $delim = "\t";
    elseif ($semis > $commas)                 $delim = ';';
    else                                       $delim = ',';

    $header = null;
    $rows   = [];

    while (($row = fgetcsv($fh, 4000, $delim)) !== false) {
        if ($header === null) {
            // First row — treat as header if cells look like labels
            $candidate = array_map(
                static fn($h) => strtolower(trim(ltrim((string)$h, "\xEF\xBB\xBF"))),
                $row
            );
            // If any cell contains a digit that looks like an erf number or
            // a valid email, treat as data with no header — use numeric keys.
            $looksLikeData = false;
            foreach ($candidate as $cell) {
                if (filter_var($cell, FILTER_VALIDATE_EMAIL)) { $looksLikeData = true; break; }
                if (ctype_digit($cell) && strlen($cell) <= 6)  { $looksLikeData = true; break; }
            }
            if ($looksLikeData) {
                // No header — use numeric keys and process this row as data
                $header = array_keys($row); // 0, 1, 2...
                $rows[] = array_combine($header, array_slice($row, 0, count($header)));
            } else {
                $header = $candidate;
            }
            continue;
        }

        if (count($row) < 1 || implode('', $row) === '') continue;

        $rows[] = array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row,    0, count($header))
        );
    }
    fclose($fh);

    if (empty($rows)) {
        return ['ok' => false, 'error' => 'No data rows found in the file. Check the file has content below the header row.'];
    }

    return ['ok' => true, 'rows' => $rows, 'format' => 'csv'];
}

// ── XLSX reader ──────────────────────────────────────────────
// Reads the first worksheet only.
// Returns associative rows keyed by lowercase header names,
// or numeric keys if the first row looks like data.
//
function gembReadXlsx(string $tmpPath): array {
    if (!gembLoadSimpleXLSX()) {
        return ['ok' => false, 'error' => 'Excel reading is not available. SimpleXLSX.php is missing from the server. Please contact the system administrator or upload a CSV file instead.'];
    }

    $xlsx = \Shuchkin\SimpleXLSX::parse($tmpPath);
    if (!$xlsx) {
        return ['ok' => false, 'error' => 'Could not parse the Excel file: ' . \Shuchkin\SimpleXLSX::parseError() . '. Try saving the file as .xlsx (not .xls or .xlsm) and re-uploading.'];
    }

    $rawRows = $xlsx->rows(0); // First worksheet
    if (empty($rawRows)) {
        return ['ok' => false, 'error' => 'The Excel file appears to be empty or the first worksheet has no data.'];
    }

    // Trim all cell values, convert to string
    $rawRows = array_map(
        static fn($row) => array_map(static fn($cell) => trim((string)$cell), $row),
        $rawRows
    );

    // Remove completely empty rows
    $rawRows = array_values(array_filter(
        $rawRows,
        static fn($row) => implode('', $row) !== ''
    ));

    if (empty($rawRows)) {
        return ['ok' => false, 'error' => 'The Excel worksheet contains no data rows.'];
    }

    // Detect header: same logic as CSV
    $firstRow  = $rawRows[0];
    $candidate = array_map('strtolower', $firstRow);

    $looksLikeData = false;
    foreach ($candidate as $cell) {
        if (filter_var($cell, FILTER_VALIDATE_EMAIL)) { $looksLikeData = true; break; }
        if (ctype_digit($cell) && strlen($cell) <= 6)  { $looksLikeData = true; break; }
    }

    if ($looksLikeData) {
        // No header row — numeric keys
        $header  = array_keys($firstRow); // 0,1,2...
        $dataRows = $rawRows;
    } else {
        $header   = $candidate;
        $dataRows = array_slice($rawRows, 1);
    }

    $rows = [];
    foreach ($dataRows as $row) {
        if (implode('', $row) === '') continue;
        $rows[] = array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row,    0, count($header))
        );
    }

    if (empty($rows)) {
        return ['ok' => false, 'error' => 'No data rows found in the Excel file.'];
    }

    return ['ok' => true, 'rows' => $rows, 'format' => 'xlsx'];
}

// ── Convenience: get extension from $_FILES entry ────────────
function gembUploadExtension(array $fileEntry): string {
    return strtolower(pathinfo($fileEntry['name'] ?? '', PATHINFO_EXTENSION));
}
