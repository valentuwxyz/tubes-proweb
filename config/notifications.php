<?php
// Helper notifikasi terpusat untuk admin, pemilik kos, dan user

function ensureNotificationsTable(mysqli $conn): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipient_user_id INT UNSIGNED DEFAULT NULL,
        recipient_role VARCHAR(20) DEFAULT NULL,
        booking_id INT UNSIGNED DEFAULT NULL,
        kos_id INT UNSIGNED DEFAULT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'info',
        title VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_recipient_user (recipient_user_id, is_read),
        INDEX idx_recipient_role (recipient_role, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    return (bool)mysqli_query($conn, $sql);
}

function createNotification(
    mysqli $conn,
    ?int $recipientUserId,
    ?string $recipientRole,
    string $type,
    string $title,
    string $message,
    ?int $bookingId = null,
    ?int $kosId = null
): bool {
    if (!ensureNotificationsTable($conn)) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO notifications
            (recipient_user_id, recipient_role, booking_id, kos_id, type, title, message, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        "isiisss",
        $recipientUserId,
        $recipientRole,
        $bookingId,
        $kosId,
        $type,
        $title,
        $message
    );
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * Mengirim notifikasi ke pemilik kos dan seluruh admin saat ada pesanan baru masuk.
 */
function notifyNewBooking(
    mysqli $conn,
    int $bookingId,
    int $kosId,
    string $kosName,
    string $tenantName,
    int $durationMonths,
    int $estimatedTotal,
    ?int $ownerId = null
): void {
    $totalFmt = 'Rp ' . number_format($estimatedTotal, 0, ',', '.');
    $title = 'Pesanan Kos Baru Masuk';
    $msg = "{$tenantName} memesan kos \"{$kosName}\" selama {$durationMonths} bulan ({$totalFmt}).";

    if ($ownerId !== null && $ownerId > 0) {
        createNotification($conn, $ownerId, 'pemilik', 'new_booking', $title, $msg, $bookingId, $kosId);
    } else {
        createNotification($conn, null, 'pemilik', 'new_booking', $title, $msg, $bookingId, $kosId);
    }

    $tblUsers = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
    if ($tblUsers && mysqli_num_rows($tblUsers) > 0) {
        $resAdmin = mysqli_query($conn, "SELECT id FROM users WHERE role = 'admin'");
        if ($resAdmin && mysqli_num_rows($resAdmin) > 0) {
            while ($adm = mysqli_fetch_assoc($resAdmin)) {
                $admId = (int)$adm['id'];
                if ($admId > 0) {
                    createNotification($conn, $admId, 'admin', 'new_booking', $title, $msg, $bookingId, $kosId);
                }
            }
            return;
        }
    }
    createNotification($conn, null, 'admin', 'new_booking', $title, $msg, $bookingId, $kosId);
}

/**
 * Mengirim notifikasi ke user ketika pemilik mengonfirmasi pesanan kosnya.
 */
function notifyBookingConfirmedToUser(
    mysqli $conn,
    int $bookingId,
    int $kosId,
    string $kosName,
    string $ownerName,
    ?int $tenantUserId = null,
    ?string $tenantName = null
): void {
    $title = 'Pesanan Kos Dikonfirmasi!';
    $msg = "Pemilik kos ({$ownerName}) telah mengonfirmasi pesanan Anda untuk kos \"{$kosName}\". Silakan lanjutkan koordinasi survei atau pembayaran saat masuk kos.";

    if ($tenantUserId !== null && $tenantUserId > 0) {
        createNotification($conn, $tenantUserId, 'user', 'booking_confirmed', $title, $msg, $bookingId, $kosId);
        return;
    }

    if ($tenantName !== null && trim($tenantName) !== '') {
        $stmtU = mysqli_prepare($conn, "SELECT id FROM users WHERE name = ? LIMIT 1");
        if ($stmtU) {
            mysqli_stmt_bind_param($stmtU, "s", $tenantName);
            mysqli_stmt_execute($stmtU);
            $resU = mysqli_stmt_get_result($stmtU);
            if ($resU && ($uRow = mysqli_fetch_assoc($resU))) {
                createNotification($conn, (int)$uRow['id'], 'user', 'booking_confirmed', $title, $msg, $bookingId, $kosId);
                mysqli_stmt_close($stmtU);
                return;
            }
            mysqli_stmt_close($stmtU);
        }
    }

    createNotification($conn, null, 'user', 'booking_confirmed', $title, $msg, $bookingId, $kosId);
}

function getUserNotifications(mysqli $conn, int $userId, string $role, int $limit = 15): array
{
    if (!ensureNotificationsTable($conn)) {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $sql = "SELECT * FROM notifications
            WHERE recipient_user_id = ? OR (recipient_user_id IS NULL AND recipient_role = ?)
            ORDER BY id DESC
            LIMIT ?";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "isi", $userId, $role, $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function getUnreadBadgeCount(mysqli $conn, int $userId, string $role): int
{
    if (!ensureNotificationsTable($conn)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) AS cnt FROM notifications
            WHERE is_read = 0
              AND (recipient_user_id = ? OR (recipient_user_id IS NULL AND recipient_role = ?))";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "is", $userId, $role);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return (int)($row['cnt'] ?? 0);
}

function markNotificationsRead(mysqli $conn, int $userId, string $role): void
{
    if (!ensureNotificationsTable($conn)) {
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE notifications SET is_read = 1
         WHERE is_read = 0 AND (recipient_user_id = ? OR (recipient_user_id IS NULL AND recipient_role = ?))"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "is", $userId, $role);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function deleteNotificationById(mysqli $conn, int $notifId, int $userId, string $role): bool
{
    if ($notifId <= 0 || !ensureNotificationsTable($conn)) {
        return false;
    }

    // Terisolasi per akun & role (admin, pemilik, maupun user hanya menghapus miliknya sendiri)
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM notifications
         WHERE id = ? AND (recipient_user_id = ? OR (recipient_user_id IS NULL AND recipient_role = ?))"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "iis", $notifId, $userId, $role);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function deleteAllNotifications(mysqli $conn, int $userId, string $role): bool
{
    if (!ensureNotificationsTable($conn)) {
        return false;
    }

    // Terisolasi per akun & role (admin, pemilik, maupun user hanya menghapus miliknya sendiri)
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM notifications
         WHERE recipient_user_id = ? OR (recipient_user_id IS NULL AND recipient_role = ?)"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "is", $userId, $role);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}