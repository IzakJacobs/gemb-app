<?php
// ============================================================
// GEMB Communications — Meeting Governance Checklist
// comms_meeting_checklist.php
//
// Version 1: READ-ONLY checklist
// No voting logic changes. No database writes.
// ============================================================

require_once __DIR__ . '/comms_core.php';
commsRequireRole('administrator', 'supervisor');

//
// CHECKLIST ACTION: Pending -> In Progress
//
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['checklist_action'] ?? '') === 'start_task') {

    // Only Administrators may start checklist tasks.
    commsRequireRole('administrator');

    verifyCsrfToken();

    $postMeetingId = filter_var(
        $_POST['meeting_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $taskId = filter_var(
        $_POST['task_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!$postMeetingId || !$taskId) {
        http_response_code(400);
        exit('Invalid meeting or checklist task.');
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        // Lock governance record and confirm it is editable.
        $stmt = $pdo->prepare("
            SELECT governance_status
            FROM meeting_governance
            WHERE meeting_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$postMeetingId]);
        $governanceStatus = $stmt->fetchColumn();

        if ($governanceStatus !== 'preparing') {
            throw new RuntimeException(
                'Checklist changes are not permitted at this governance stage.'
            );
        }

        // Lock the selected checklist task.
        $stmt = $pdo->prepare("
            SELECT id, task_code, status
            FROM meeting_checklist
            WHERE id = ? AND meeting_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$taskId, $postMeetingId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task || $task['status'] !== 'pending') {
            throw new RuntimeException(
                'Only a pending checklist task can be started.'
            );
        }

        $adminId = commsCurrentUserId();

        if (!$adminId) {
            throw new RuntimeException('Administrator session is invalid.');
        }

        // Change status.
        $stmt = $pdo->prepare("
            UPDATE meeting_checklist
            SET status = 'in_progress'
            WHERE id = ? AND meeting_id = ?
              AND status = 'pending'
        ");
        $stmt->execute([$taskId, $postMeetingId]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'The checklist task could not be updated.'
            );
        }

        // Record the change in the mandatory governance audit log.
        $stmt = $pdo->prepare("
            INSERT INTO meeting_audit_log
                (meeting_id, admin_id, action,
                 entity_type, entity_id,
                 old_value, new_value, detail, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $postMeetingId,
            $adminId,
            'checklist_started',
            'meeting_checklist',
            $taskId,
            'pending',
            'in_progress',
            'Task: ' . $task['task_code'],
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        setFlash('success', 'Checklist activity started.');

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Meeting checklist update failed: ' . $e->getMessage()
        );

        setFlash(
            'error',
            $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Unable to update checklist activity.'
        );
    }

    header(
        'Location: comms_meeting_checklist.php?id=' . $postMeetingId
    );
    exit;
}

// Validate the meeting ID.
$meetingId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$meetingId) {
    http_response_code(400);
    exit('Invalid meeting ID.');
}

// Retrieve meeting and governance details.
$stmt = db()->prepare("
    SELECT
        m.id,
        m.title,
        m.meeting_date,
        m.status AS voting_status,
        m.is_test,
        g.meeting_type,
        g.governance_status,
        g.started_at,
        g.archived_at
    FROM meetings m
    INNER JOIN meeting_governance g
        ON g.meeting_id = m.id
    WHERE m.id = ?
    LIMIT 1
");

$stmt->execute([$meetingId]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    http_response_code(404);
    exit('Meeting governance record not found.');
}

// Retrieve the checklist.
$stmt = db()->prepare("
    SELECT
        id,
        task_code,
        task_title,
        status,
        due_date,
        completed_by,
        completed_at,
        verified_by,
        verified_at,
        na_reason,
        notes
    FROM meeting_checklist
    WHERE meeting_id = ?
    ORDER BY id ASC
");

$stmt->execute([$meetingId]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Safe HTML output helper, local to this page.
function checklistEscape($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function checklistStatusLabel(string $status): string {
    $labels = [
        'pending'        => 'Pending',
        'in_progress'    => 'In Progress',
        'completed'      => 'Completed',
        'verified'       => 'Verified',
        'not_applicable' => 'Not Applicable'
    ];

    return $labels[$status] ?? 'Unknown';
}

pageHeader('Meeting Governance Checklist', 'admin');
renderHeader('Meeting Governance Checklist', 'comms_meetings.php');
?>

<main class="pc-main">
    <div class="card card-wide">

        <h2 class="card-title">
            Meeting Governance Checklist
        </h2>

        <p style="margin:16px 0;">
            <a href="comms_meetings.php" class="btn btn-blue">
                &larr; Back to Meeting Management
            </a>
        </p>

        <h3>
            Meeting #<?= (int)$meeting['id'] ?>:
            <?= checklistEscape($meeting['title']) ?>

            <?php if ((int)$meeting['is_test'] === 1): ?>
                <span class="badge badge-red">TEST</span>
            <?php endif; ?>
        </h3>

        <p style="margin:12px 0 20px;">
            <strong>Meeting type:</strong>
            <?= checklistEscape($meeting['meeting_type']) ?>

            &nbsp; | &nbsp;

            <strong>Date:</strong>
            <?php if ($meeting['meeting_date']): ?>
                <?= checklistEscape(
                    date('d M Y', strtotime($meeting['meeting_date']))
                ) ?>
            <?php else: ?>
                Not specified
            <?php endif; ?>

            &nbsp; | &nbsp;

            <strong>Voting status:</strong>
            <?= checklistEscape(
                strtoupper($meeting['voting_status'])
            ) ?>

            &nbsp; | &nbsp;

            <strong>Governance status:</strong>
            <?= checklistEscape(
                ucwords(str_replace('_', ' ', $meeting['governance_status']))
            ) ?>
        </p>

        <?php if (empty($tasks)): ?>

            <div class="alert alert-info">
                No governance checklist tasks were found.
            </div>

        <?php else: ?>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Checklist Activity</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Completed</th>
                            <th>Verified</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($tasks as $index => $task): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td>
                                <strong>
                                    <?= checklistEscape($task['task_title']) ?>
                                </strong>
                            </td>

                           
<td>
    <?= checklistEscape(
        checklistStatusLabel($task['status'])
    ) ?>

    <?php if (
    $task['status'] === 'pending'
    && $meeting['governance_status'] === 'preparing'
    && commsCurrentUserRole() === 'administrator'
): ?>

        <form method="POST"
              action="comms_meeting_checklist.php"
              style="margin-top:6px;">

            <?= csrfField() ?>

            <input type="hidden"
                   name="checklist_action"
                   value="start_task">

            <input type="hidden"
                   name="meeting_id"
                   value="<?= (int)$meeting['id'] ?>">

            <input type="hidden"
                   name="task_id"
                   value="<?= (int)$task['id'] ?>">

            <button type="submit"
                    class="btn btn-sm btn-blue">
                Start
            </button>
        </form>

    <?php endif; ?>
</td>


                            <td>
                                <?php if ($task['due_date']): ?>
                                    <?= checklistEscape($task['due_date']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($task['completed_at']): ?>
                                    <?= checklistEscape($task['completed_at']) ?>
                                    <div class="muted-note">
                                        User #<?= (int)$task['completed_by'] ?>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($task['verified_at']): ?>
                                    <?= checklistEscape($task['verified_at']) ?>
                                    <div class="muted-note">
                                        User #<?= (int)$task['verified_by'] ?>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
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
