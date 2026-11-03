<?php
// ============================================================
// GEMB Communications — Secure Document Download
// comms_document_download.php
// ============================================================

require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

$documentId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$documentId) {
    http_response_code(400);
    exit('Invalid document ID.');
}

$stmt = db()->prepare("
    SELECT
        filename,
        filetype,
        filesize,
        filedata,
        sha256_hash
    FROM comms_documents
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$document) {
    http_response_code(404);
    exit('Document not found.');
}

$fileData = $document['filedata'];
$storedHash = $document['sha256_hash'];

if (!is_string($fileData)) {
    error_log('Document data missing for ID ' . $documentId);
    http_response_code(500);
    exit('Document unavailable.');
}

// Verify stored content integrity.
if ($storedHash !== null &&
    !hash_equals(
        strtolower($storedHash),
        hash('sha256', $fileData)
    )) {

    error_log(
        'Document integrity check failed for ID ' . $documentId
    );

    http_response_code(500);
    exit('Document integrity verification failed.');
}

if (strlen($fileData) !== (int)$document['filesize']) {
    error_log('Document size mismatch for ID ' . $documentId);
    http_response_code(500);
    exit('Document integrity verification failed.');
}

// Do not trust stored filenames or MIME types blindly.
$filename = basename(
    str_replace('\\', '/', (string)$document['filename'])
);

$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png'
];

if (!isset($mimeTypes[$extension]) ||
    $document['filetype'] !== $mimeTypes[$extension]) {
    http_response_code(500);
    exit('Unsupported document type.');
}

// Sanitize filename for the Content-Disposition header.
$safeFilename = preg_replace(
    '/[^A-Za-z0-9._-]/',
    '_',
    $filename
);

if (!$safeFilename || $safeFilename === '.' ||
    $safeFilename === '..') {
    $safeFilename = 'document_' . $documentId . '.' . $extension;
}

// Prevent caching of potentially confidential documents.
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . strlen($fileData));
header(
    'Content-Disposition: attachment; filename="' .
    $safeFilename . '"'
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

echo $fileData;
exit;
