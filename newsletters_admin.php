<?php
// ============================================================
// GEMB Access Control — newsletters_admin.php (fixed)
// newsletters columns: id, filename, filetype, filesize,
//   filedata (blob), synopsis, file_date, category,
//   uploaded_by, uploaded_at
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$action = $_GET['action'] ?? 'upload';

if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $synopsis = trim($_POST['synopsis'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $fileDate = $_POST['file_date'] ?? date('Y-m-d');

        if (!empty($_FILES['document']['name']) && $synopsis) {
            $ext     = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','jpg','jpeg','png'];
            if (!in_array($ext, $allowed)) {
                setFlash('error', 'Only PDF, Word, and image files allowed.');
            } elseif ($_FILES['document']['size'] > 10 * 1024 * 1024) {
                setFlash('error', 'File too large. Maximum 10MB.');
            } else {
                $filedata = file_get_contents($_FILES['document']['tmp_name']);
                $mimeMap  = [
                    'pdf'  => 'application/pdf',
                    'doc'  => 'application/msword',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                ];
                $filetype = $mimeMap[$ext] ?? 'application/octet-stream';
                db()->prepare("INSERT INTO newsletters
                    (filename, filetype, filesize, filedata, synopsis, file_date, category, uploaded_by)
                    VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    basename($_FILES['document']['name']),
                    $filetype,
                    $_FILES['document']['size'],
                    $filedata,
                    $synopsis,
                    $fileDate,
                    $category,
                    $_SESSION['admin_name'] ?? 'admin',
                ]);
                setFlash('success', 'Document uploaded and published.');
            }
        } else {
            setFlash('error', 'Title and file are required.');
        }
        header('Location: newsletters_admin.php?action=upload'); exit;
    }

    // ── Line 40: fixed to use uploaded_at ─────────────────
    $letters = db()->query("SELECT id, filename, synopsis, file_date, category, filesize, uploaded_at FROM newsletters ORDER BY uploaded_at DESC")->fetchAll();

    pageHeader('Newsletters', 'admin');
    renderHeader('📄 Newsletters & Notices', 'admin.php?action=menu');
    ?>
    <div class="container">
      <?= getFlash() ?>
      <div class="card">
        <div class="card-title">Upload New Document</div>
        <form method="POST" enctype="multipart/form-data">
          <div class="form-group"><label>Title / Synopsis *</label>
            <input type="text" name="synopsis" required placeholder="e.g. May 2026 Estate Newsletter">
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label>Category</label>
              <select name="category">
                <option>General</option>
                <option>Newsletter</option>
                <option>Notice</option>
                <option>Minutes</option>
                <option>Financial</option>
                <option>Rules</option>
              </select>
            </div>
            <div class="form-group"><label>Document Date</label>
              <input type="date" name="file_date" value="<?= date('Y-m-d') ?>">
            </div>
          </div>
          <div class="form-group"><label>File (PDF, Word, or Image — max 10MB) *</label>
            <input type="file" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
          </div>
          <button type="submit" class="btn btn-primary">Upload & Publish</button>
        </form>
      </div>
      <div class="card">
        <div class="card-title">Published Documents (<?= count($letters) ?>)</div>
        <?php if (empty($letters)): ?>
        <p style="color:#666;">No documents uploaded yet.</p>
        <?php else: foreach ($letters as $l): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eee;flex-wrap:wrap;gap:8px;">
          <div>
            <strong><?= htmlspecialchars($l['synopsis'] ?: $l['filename']) ?></strong>
            <?php if ($l['category']): ?>
            <span style="font-size:.78rem;color:#666;"> — <?= htmlspecialchars($l['category']) ?></span>
            <?php endif; ?>
            <div style="font-size:.75rem;color:#999;">
              <?= $l['file_date'] ? date('d M Y', strtotime($l['file_date'])) : date('d M Y', strtotime($l['uploaded_at'])) ?>
              &nbsp;|&nbsp; <?= round(($l['filesize'] ?? 0) / 1024) ?>KB
            </div>
          </div>
          <div style="display:flex;gap:6px;">
            <a href="newsletter_download.php?id=<?= $l['id'] ?>" target="_blank" class="btn btn-primary btn-sm">View</a>
            <a href="newsletters_admin.php?action=delete&id=<?= $l['id'] ?>"
               class="btn btn-danger btn-sm"
               onclick="return confirm('Delete this document?')">Delete</a>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php pageFooter(); exit;
}

if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? 0);
    db()->prepare("DELETE FROM newsletters WHERE id=?")->execute([$id]);
    setFlash('success', 'Document deleted.');
    header('Location: newsletters_admin.php?action=upload'); exit;
}
