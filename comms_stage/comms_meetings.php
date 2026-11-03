<?php
// ============================================================
// GEMB Communications — Meeting Management
// comms_meetings.php
//
// Version 1: READ-ONLY meeting list
// No database changes. No voting logic changes.
// ============================================================

require_once __DIR__ . '/comms_core.php';
commsRequireRole('administrator', 'supervisor');

// ============================================================
// START GOVERNANCE
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['governance_action'] ?? '') === 'start') {

    // Only an Administrator may start governance.
    commsRequireRole('administrator');

    verifyCsrfToken();

    $meetingId = filter_var(
        $_POST['meeting_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $meetingType = $_POST['meeting_type'] ?? '';

    if (!$meetingId || !in_array($meetingType, ['AGM', 'SGM'], true)) {
        setFlash('error', 'Invalid meeting or meeting type.');
        header('Location: comms_meetings.php');
        exit;
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Confirm and lock the existing meeting.
        $stmt = $pdo->prepare(
            "SELECT id FROM meetings WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$meetingId]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Meeting not found.');
        }

        // Prevent duplicate governance initialization.
        $stmt = $pdo->prepare(
            "SELECT id FROM meeting_governance WHERE meeting_id = ?"
        );
        $stmt->execute([$meetingId]);

        if ($stmt->fetchColumn()) {
            throw new RuntimeException(
                'Governance has already been started for this meeting.'
            );
        }

        $adminId = commsCurrentUserId();

        if (!$adminId) {
            throw new RuntimeException('Administrator session is invalid.');
        }

        // Create governance record.
        $stmt = $pdo->prepare(
            "INSERT INTO meeting_governance
                (meeting_id, meeting_type, started_by)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$meetingId, $meetingType, $adminId]);

        // Initial standard checklist.
        $tasks = [
            ['MEETING_CREATED', 'Meeting created'],
            ['AGENDA_INVITATION', 'Invitation for agenda items issued'],
            ['AGENDA_SUBMISSIONS', 'Agenda submissions recorded'],
            ['AGENDA_APPROVAL', 'Board approval of agenda'],
            ['FORMAL_NOTICE', 'Formal meeting notice issued'],
            ['NOMINATIONS', 'Director nominations processed'],
            ['VOTER_REGISTER', 'Voter register approved'],
            ['PROXIES', 'Proxy appointments validated'],
            ['ATTENDANCE_QUORUM', 'Attendance and quorum established'],
            ['PROCEEDINGS', 'Meeting proceedings recorded'],
            ['VOTING_RESULTS', 'Voting and results preserved'],
            ['MINUTES_APPROVAL', 'Minutes approved'],
            ['ARCHIVE', 'Meeting governance archived']
        ];

        $insertTask = $pdo->prepare(
            "INSERT INTO meeting_checklist
                (meeting_id, task_code, task_title)
             VALUES (?, ?, ?)"
        );

        foreach ($tasks as [$code, $title]) {
            $insertTask->execute([$meetingId, $code, $title]);
        }

        // Mandatory audit entry, in the same transaction.
        $audit = $pdo->prepare(
            "INSERT INTO meeting_audit_log
                (meeting_id, admin_id, action,
                 entity_type, entity_id, new_value, detail, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $audit->execute([
            $meetingId,
            $adminId,
            'governance_started',
            'meeting_governance',
            $meetingId,
            $meetingType,
            'Governance checklist initialized',
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        setFlash(
            'success',
            'Meeting governance started successfully.'
        );

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Start Governance failed: ' . $e->getMessage());

        setFlash(
            'error',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to start governance.'
        );
    }

    header('Location: comms_meetings.php');
    exit;
}


// Retrieve existing meetings using the established database.


$stmt = db()->query("
    SELECT
        m.id,
        m.estate_id,
        m.title,
        m.meeting_date,
        m.status,
        m.is_test,
        e.official_name AS estate_official_name,
        e.short_name AS estate_short_name,
        g.id AS governance_id,
        g.meeting_type,
        g.governance_status
    FROM meetings m
    LEFT JOIN comms_estates e
        ON e.id = m.estate_id
    LEFT JOIN meeting_governance g
        ON g.meeting_id = m.id
    ORDER BY m.created_at DESC, m.id DESC
");

$meetings = $stmt->fetchAll();

pageHeader('Meeting Management', 'admin');
renderHeader('Meeting Management', 'comms_menu.php');
?>

<main class="pc-main">

    <div class="card card-wide">

        <h2 class="card-title">Meeting Management</h2>
       
      <?php if (commsCurrentUserRole() === 'administrator'): ?>
    <p style="margin:16px 0;">
        <a href="comms_voting.php?action=meetings_create"
           class="btn btn-blue">
            + New Meeting
        </a>
    </p>
<?php endif; ?>

        <p class="muted-note" style="margin-bottom:16px;">
            Select an existing AGM, SGM or test meeting.
            Meeting governance functions will be added
            progressively without changing the existing
            voting system.
        </p>

        <?php if (empty($meetings)): ?>

            <div class="alert alert-info">
                No meetings have been created yet.
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Meeting</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($meetings as $meeting): ?>

                        <?php
                       if ($status === 'open') {
    $statusClass = 'badge-green';
} elseif ($status === 'closed') {
    $statusClass = 'badge-grey';
} else {
    $statusClass = 'badge-amber';
}
                        ?>

                        <tr>
                            <td>
                                <?= (int)$meeting['id'] ?>
                            </td>

                           
<td>
    <?php if (!empty($meeting['estate_official_name'])): ?>

        <div style="font-weight:700; color:#174a96; margin-bottom:5px;">
            <?= htmlspecialchars(
                $meeting['estate_official_name'],
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>

    <?php else: ?>

        <div style="color:#a65b00; font-weight:600; margin-bottom:5px;">
            Estate not assigned
        </div>

    <?php endif; ?>

    <strong>
        <?= htmlspecialchars(
            $meeting['title'],
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </strong>

    <?php if ((int)$meeting['is_test'] === 1): ?>

                                
                                    <span class="badge badge-red">
                                        TEST
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($meeting['meeting_date']): ?>
                                    <?= htmlspecialchars(
                                        date(
                                            'd M Y',
                                            strtotime($meeting['meeting_date'])
                                        )
                                    ) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                           <td>
                            <strong style="color:#333333; font-weight:700;">
                            <?= htmlspecialchars(strtoupper((string)$meeting['status']), ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                            </td>

                            
<td>
    <?php if (empty($meeting['governance_id'])): ?>
    <?php if (commsCurrentUserRole() === 'administrator'): ?>

        <form method="POST" action="comms_meetings.php"
              style="display:inline-block; margin-right:8px;">

            <?= csrfField() ?>

            <input type="hidden"
                   name="governance_action"
                   value="start">

            <input type="hidden"
                   name="meeting_id"
                   value="<?= (int)$meeting['id'] ?>">

            <select name="meeting_type" required
                    style="padding:7px; margin-right:5px;">
                <option value="AGM">AGM</option>
                <option value="SGM">SGM</option>
            </select>

            <button type="submit"
                    class="btn btn-sm btn-blue"
                    onclick="return confirm('Start governance for this meeting?')">
                Start Governance
            </button>
               </form>

    <?php endif; ?>

    <?php else: ?>

        <strong>
            <?= htmlspecialchars($meeting['meeting_type'], ENT_QUOTES, 'UTF-8') ?>
        </strong>
        <span class="badge badge-green">
            Governance Started
        </span>
        
        
<a
    href="comms_meeting_checklist.php?id=<?= (int)$meeting['id'] ?>"
    class="btn btn-sm btn-blue"
    style="margin-left:8px;"
>
    View Checklist
</a>

    <?php endif; ?>

    <a class="btn btn-sm btn-blue"
       href="comms_voting.php?action=meetings_list">
        Voting
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
