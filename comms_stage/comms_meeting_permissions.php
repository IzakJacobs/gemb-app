<?php
declare(strict_types=1);

/**
 * GEMB staging — meeting-specific staff permissions.
 *
 * This file does not grant permissions based on the
 * global comms_users.role field.
 *
 * Include it from an authenticated application page
 * that has already loaded config.php.
 */

function commsMeetingAssignment(
    int $meetingId,
    int $userId
): ?array {
    if ($meetingId <= 0 || $userId <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT a.id,
                a.meeting_id,
                a.user_id,
                a.meeting_role
         FROM meeting_staff_assignments a
         INNER JOIN comms_users u
                 ON u.id = a.user_id
         WHERE a.meeting_id = ?
           AND a.user_id = ?
           AND a.active = 1
           AND u.active = 1
         LIMIT 1'
    );

    $stmt->execute([$meetingId, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function commsHasMeetingRole(
    int $meetingId,
    int $userId,
    string ...$allowedRoles
): bool {
    $assignment = commsMeetingAssignment(
        $meetingId,
        $userId
    );

    return $assignment !== null
        && in_array(
            $assignment['meeting_role'],
            $allowedRoles,
            true
        );
}

function commsHasMeetingTask(
    int $meetingId,
    int $userId,
    string $taskCode
): bool {
    $assignment = commsMeetingAssignment(
        $meetingId,
        $userId
    );

    if ($assignment === null) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1
         FROM meeting_staff_tasks
         WHERE assignment_id = ?
           AND task_code = ?
         LIMIT 1'
    );

    $stmt->execute([
        $assignment['id'],
        $taskCode
    ]);

    return (bool) $stmt->fetchColumn();
}

function commsRequireMeetingRole(
    int $meetingId,
    string ...$allowedRoles
): void {
    commsRequireAuth();

    $userId = commsCurrentUserId();

    if ($userId === null ||
        !commsHasMeetingRole(
            $meetingId,
            $userId,
            ...$allowedRoles
        )
    ) {
        http_response_code(403);
        exit('Access denied for this meeting.');
    }
}

function commsRequireMeetingTask(
    int $meetingId,
    string $taskCode
): void {
    commsRequireAuth();

    $userId = commsCurrentUserId();

    if ($userId === null ||
        !commsHasMeetingTask(
            $meetingId,
            $userId,
            $taskCode
        )
    ) {
        http_response_code(403);
        exit('Not authorised for this meeting task.');
    }
}
