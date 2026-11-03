<?php
// GEMB Communications — comms_otp_helper.php
// PHP 8.3
// Administrator email OTP; independent of owner voting OTP.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smtp_mail.php';

if (!defined('COMMS_OTP_TTL')) {
    define('COMMS_OTP_TTL', 600);
}

function commsEnsureOtpTable(): void
{
    // Fail closed if the table cannot be created or accessed.
    db()->exec(
        "CREATE TABLE IF NOT EXISTS comms_otp_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}


function generateCommsEmailOtp(string $email): bool
{
    $email = trim($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $otpId = 0;

    try {
        commsEnsureOtpTable();

        $pdo = db();
        $pdo->beginTransaction();

        try {
            /*
             * Lock this administrator's account row.
             * Simultaneous OTP requests for the same account
             * must wait for the first transaction to finish.
             */
            $stmt = $pdo->prepare(
                "SELECT id
                 FROM comms_users
                 WHERE email = ? AND active = 1
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$email]);
            $userId = (int)$stmt->fetchColumn();

            if ($userId <= 0) {
                $pdo->rollBack();
                return false;
            }

            /*
             * Count OTP submission attempts while holding
             * the administrator row lock.
             */
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM comms_login_log
                 WHERE email = ?
                   AND event = 'otp_sent'
                   AND logged_at >=
                       DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
            );
            $stmt->execute([$email]);

            if ((int)$stmt->fetchColumn() >= 3) {
                $pdo->rollBack();
                return false;
            }

            $otp = str_pad(
                (string)random_int(0, 999999),
                6,
                '0',
                STR_PAD_LEFT
            );

            /*
             * Invalidate previous unused OTPs.
             */
            $stmt = $pdo->prepare(
                "UPDATE comms_otp_log
                 SET used = 1
                 WHERE email = ? AND used = 0"
            );
            $stmt->execute([$email]);

            /*
             * Store the new OTP.
             */
            $stmt = $pdo->prepare(
                "INSERT INTO comms_otp_log
                    (email, otp_hash, expires_at)
                 VALUES (
                    ?,
                    ?,
                    DATE_ADD(NOW(), INTERVAL 600 SECOND)
                 )"
            );
            $stmt->execute([
                $email,
                password_hash($otp, PASSWORD_BCRYPT)
            ]);

            $otpId = (int)$pdo->lastInsertId();

            /*
             * Reserve one of the three allowed attempts
             * BEFORE sending the email.
             *
             * This insert must succeed or the transaction
             * is rolled back and no email is submitted.
             */
            $stmt = $pdo->prepare(
                "INSERT INTO comms_login_log
                    (user_id, email, event,
                     ip_address, user_agent, detail)
                 VALUES (?, ?, 'otp_sent', ?, ?, ?)"
            );
            $stmt->execute([
                $userId,
                $email,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr(
                    $_SERVER['HTTP_USER_AGENT'] ?? '',
                    0,
                    255
                ),
                'Administrator OTP submission attempt'
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        /*
         * The rate-limit reservation has been committed.
         * Only now submit the email.
         */
        $html =
            '<p>A sign-in attempt was made to the GEMB ' .
            'Communications Portal.</p>' .
            '<p>Your verification code is:</p>' .
            '<p style="font-size:28px;font-weight:bold;' .
            'letter-spacing:4px">' . $otp . '</p>' .
            '<p>This code expires in 10 minutes.</p>' .
            '<p>If you did not attempt to sign in, ' .
            'please contact your administrator.</p>';

        $sent = smtpSend(
            $email,
            'GEMB Comms - Sign-in Verification Code',
            $html
        );

        if (!$sent) {
            // Disable the OTP if email submission failed.
            $stmt = db()->prepare(
                "UPDATE comms_otp_log
                 SET used = 1
                 WHERE id = ?"
            );
            $stmt->execute([$otpId]);

            return false;
        }

        return true;
    } catch (\Throwable $e) {
        error_log(
            'generateCommsEmailOtp failed: ' .
            $e->getMessage()
        );

        // Best effort: invalidate an OTP if an error
        // occurred after its transaction committed.
        if ($otpId > 0) {
            try {
                db()->prepare(
                    "UPDATE comms_otp_log
                     SET used = 1
                     WHERE id = ?"
                )->execute([$otpId]);
            } catch (\Throwable $cleanupError) {
                error_log(
                    'OTP cleanup failed: ' .
                    $cleanupError->getMessage()
                );
            }
        }

        return false;
    }
}
function verifyCommsEmailOtp(
    string $email,
    string $otp
): bool {
    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/^\d{6}$/D', $otp)
    ) {
        return false;
    }

    try {
        commsEnsureOtpTable();

        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Lock the selected code until it is checked
            // and consumed, preventing concurrent reuse.
            $stmt = $pdo->prepare(
                "SELECT id, otp_hash
                 FROM comms_otp_log
                 WHERE email = ?
                   AND used = 0
                   AND expires_at > NOW()
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch();

            if (
                !$row ||
                !password_verify($otp, $row['otp_hash'])
            ) {
                $pdo->rollBack();
                return false;
            }

            $stmt = $pdo->prepare(
                "UPDATE comms_otp_log
                 SET used = 1
                 WHERE id = ? AND used = 0"
            );
            $stmt->execute([(int)$row['id']]);

            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } catch (\Throwable $e) {
        error_log(
            'verifyCommsEmailOtp failed: ' . $e->getMessage()
        );
        return false;
    }
}

// Retained for compatibility with existing code.
// Administrator login no longer trusts devices to bypass OTP.
function commsHashDeviceToken(string $token): string
{
    if (
        !defined('COMMS_HMAC_SECRET') ||
        COMMS_HMAC_SECRET === ''
    ) {
        throw new \RuntimeException(
            'COMMS_HMAC_SECRET is not configured.'
        );
    }

    return hash_hmac('sha256', $token, COMMS_HMAC_SECRET);
}

