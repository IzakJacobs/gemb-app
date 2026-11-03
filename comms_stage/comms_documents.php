<?php
// ============================================================
// GEMB Communications — General Estate Documents
// comms_documents.php
//
// Version 1: Read-only document library.
// Uploading and downloading will be added separately.
// ============================================================

require_once __DIR__ . '/comms_core.php';
commsRequireAuth();


//
// GENERAL ESTATE DOCUMENT UPLOAD
// Maximum file size: 10 MB
//
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['document_action'] ?? '') === 'upload') {

    verifyCsrfToken();

    $synopsis = trim((string)($_POST['synopsis'] ?? ''));
    $category = (string)($_POST['category'] ?? '');
    $fileDate = (string)($_POST['file_date'] ?? '');

    $allowedCategories = [
        'General',
        'Newsletter',
        'Notice',
        'Minutes',
        'Financial',
        'Rules'
    ];

    $allowedTypes = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png']
    ];

    try {
        if ($synopsis === '' || mb_strlen($synopsis) > 500) {
            throw new RuntimeException(
                'Enter a document title of no more than 500 characters.'
            );
        }

        if (!in_array($category, $allowedCategories, true)) {
            throw new RuntimeException('Invalid document category.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fileDate);

        if (!$date || $date->format('Y-m-d') !== $fileDate) {
            throw new RuntimeException('Invalid document date.');
        }

        if (!isset($_FILES['document'])
            || !is_array($_FILES['document'])) {
            throw new RuntimeException('Please select a document.');
        }

        $file = $_FILES['document'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(
                'The file upload did not complete successfully.'
            );
        }

        $maxSize = 10 * 1024 * 1024;

        if ($file['size'] <= 0 || $file['size'] > $maxSize) {
            throw new RuntimeException(
                'Document must be between 1 byte and 10 MB.'
            );
        }

        $tmpName = $file['tmp_name'];

        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Invalid uploaded file.');
        }

        $filename = basename(
            str_replace('\\', '/', (string)$file['name'])
        );

        if ($filename === '' || strlen($filename) > 255) {
            throw new RuntimeException('Invalid document filename.');
        }

        $extension = strtolower(
            pathinfo($filename, PATHINFO_EXTENSION)
        );

        if (!isset($allowedTypes[$extension])) {
            throw new RuntimeException(
                'Only PDF, Word, JPG and PNG documents are allowed.'
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedType = $finfo->file($tmpName);

        if (!in_array(
            $detectedType,
            $allowedTypes[$extension],
            true
        )) {
            throw new RuntimeException(
                'The document content does not match its file type.'
            );
        }

        // Additional signature checks for common file formats.
        $handle = fopen($tmpName, 'rb');

        if (!$handle) {
            throw new RuntimeException('Unable to read uploaded document.');
        }

        $signature = fread($handle, 8);
        fclose($handle);

        if ($extension === 'pdf'
            && strncmp($signature, '%PDF-', 5) !== 0) {
            throw new RuntimeException('Invalid PDF file.');
        }

        if ($extension === 'png'
            && $signature !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('Invalid PNG file.');
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)
            && substr($signature, 0, 3) !== "\xFF\xD8\xFF") {
            throw new RuntimeException('Invalid JPEG file.');
        }

        $fileData = file_get_contents($tmpName);

        if ($fileData === false) {
            throw new RuntimeException('Unable to read uploaded document.');
        }

        $hash = hash('sha256', $fileData);
        $adminId = commsCurrentUserId();

        if (!$adminId) {
            throw new RuntimeException('Administrator session is invalid.');
        }

        $stmt = db()->prepare("
            INSERT INTO comms_documents
                (filename, filetype, filesize, filedata,
                 synopsis, file_date, category,
                 uploaded_by, sha256_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $filename,
            $detectedType,
            strlen($fileData),
            $fileData,
            $synopsis,
            $fileDate,
            $category,
            $adminId,
            $hash
        ]);

        setFlash('success', 'Document uploaded successfully.');

    } catch (Throwable $e) {

        error_log(
            'GEMB document upload failed: ' . $e->getMessage()
        );

        setFlash(
            'error',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to upload document.'
        );
    }

    header('Location: comms_documents.php');
    exit;
}

// Retrieve document metadata only.
// Do not load the document BLOB into the listing.
$stmt = db()->query("
    SELECT
        id,
        filename,
        synopsis,
        category,
        file_date,
        filesize,
        uploaded_at
    FROM comms_documents
    ORDER BY uploaded_at DESC, id DESC
");

$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

pageHeader('General Estate Documents', 'admin');
renderHeader('General Estate Documents', 'comms_menu.php');
?>

<main class="pc-main">
    <div class="card card-wide">

        <h2 class="card-title">
            General Estate Documents
        </h2>

        <p style="margin:16px 0;">
            <a href="comms_menu.php" class="btn btn-blue">
                &larr; Back to Communications Menu
            </a>
        </p>


<div class="card" style="margin-bottom:24px;">

    <h3 class="card-title">Upload New Document</h3>

    <form method="POST"
          action="comms_documents.php"
          enctype="multipart/form-data">

        <?= csrfField() ?>

        <input type="hidden"
               name="document_action"
               value="upload">

        <div class="form-group">
            <label for="synopsis">Document Title / Synopsis *</label>
            <input type="text"
                   id="synopsis"
                   name="synopsis"
                   maxlength="500"
                   required
                   style="width:100%;"
                   placeholder="Enter document title">
        </div>

        <div class="form-group">
            <label for="category">Category *</label>
            <select id="category"
                    name="category"
                    required>
                <option value="General">General</option>
                <option value="Newsletter">Newsletter</option>
                <option value="Notice">Notice</option>
                <option value="Minutes">Minutes</option>
                <option value="Financial">Financial</option>
                <option value="Rules">Rules</option>
            </select>
        </div>

        <div class="form-group">
            <label for="file_date">Document Date *</label>
            <input type="date"
                   id="file_date"
                   name="file_date"
                   value="<?= date('Y-m-d') ?>"
                   required>
        </div>

        <div class="form-group">
            <label for="document">
                Select Document (PDF, Word, JPG or PNG — maximum 10 MB) *
            </label>
            <input type="file"
                   id="document"
                   name="document"
                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                   required>
        </div>

        <button type="submit" class="btn btn-blue">
            Upload Document
        </button>

    </form>
</div>

        <p class="muted-note" style="margin-bottom:16px;">
            This library will contain general estate documents,
            including newsletters, notices, financial statements,
            rules and minutes.
        </p>

        <?php if (empty($documents)): ?>

            <div class="alert alert-info">
                No documents have been uploaded yet.
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Category</th>
                            <th>Document Date</th>
                            <th>Size</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= htmlspecialchars(
                                            $document['synopsis'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </strong>
                                    <div class="muted-note">
                                        <?= htmlspecialchars(
                                            $document['filename'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= htmlspecialchars(
                                        $document['category'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                                <td>
                                    <?= $document['file_date']
                                        ? htmlspecialchars(
                                            $document['file_date'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                        : '—' ?>
                                </td>
                                <td>
                                    <?= number_format(
                                        (int)$document['filesize'] / 1024,
                                        0
                                    ) ?> KB
                                </td>
                                
                                <td>
                                    <a
                                    href="comms_document_download.php?id=<?= (int)$document['id'] ?>"
                                    class="btn btn-sm btn-blue"
                                    >
                                    Download
                                    </a>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>
</main>

<?php pageFooter(); ?>
