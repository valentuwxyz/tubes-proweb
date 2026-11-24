<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

date_default_timezone_set('Asia/Jakarta');

requireLogin('login.php');
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? 'user');

function ensureUserBookingVisibilityColumn(mysqli $conn): void
{
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'bookings'");
    if (!$tbl || mysqli_num_rows($tbl) === 0) {
        return;
    }

    $colsToAdd = [
        'hidden_by_owner' => "ALTER TABLE bookings ADD COLUMN hidden_by_owner TINYINT(1) NOT NULL DEFAULT 0",
        'hidden_by_user'  => "ALTER TABLE bookings ADD COLUMN hidden_by_user TINYINT(1) NOT NULL DEFAULT 0",
        'hidden_by_admin' => "ALTER TABLE bookings ADD COLUMN hidden_by_admin TINYINT(1) NOT NULL DEFAULT 0",
    ];

    foreach ($colsToAdd as $colName => $alterSql) {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE '{$colName}'");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            mysqli_query($conn, $alterSql);
        }
    }
}

ensureUserBookingVisibilityColumn($conn);

// Handler POST untuk hapus satu / hapus semua notifikasi & riwayat pemesanan user (terpisah dari pemilik/admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['post_action'] ?? '');

    if ($postAction === 'delete_one_notif') {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        if ($notifId > 0) {
            deleteNotificationById($conn, $notifId, $userId, $userRole);
        }
        header("Location: pesanan-user.php");
        exit;
    }

    if ($postAction === 'delete_all_notif') {
        deleteAllNotifications($conn, $userId, $userRole);
        header("Location: pesanan-user.php");
        exit;
    }

    if ($postAction === 'delete_one_history') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId > 0) {
            $stmtDel = mysqli_prepare(
                $conn,
                "UPDATE bookings SET hidden_by_user = 1 WHERE id = ? AND user_id = ? AND LOWER(status) IN ('confirmed', 'cancelled')"
            );
            if ($stmtDel) {
                mysqli_stmt_bind_param($stmtDel, "ii", $bookingId, $userId);
                mysqli_stmt_execute($stmtDel);
                mysqli_stmt_close($stmtDel);
            }
        }
        header("Location: pesanan-user.php");
        exit;
    }

    if ($postAction === 'delete_all_history') {
        $stmtDelAll = mysqli_prepare(
            $conn,
            "UPDATE bookings SET hidden_by_user = 1 WHERE user_id = ? AND LOWER(status) IN ('confirmed', 'cancelled')"
        );
        if ($stmtDelAll) {
            mysqli_stmt_bind_param($stmtDelAll, "i", $userId);
            mysqli_stmt_execute($stmtDelAll);
            mysqli_stmt_close($stmtDelAll);
        }
        header("Location: pesanan-user.php");
        exit;
    }
}

// Tandai notifikasi user sebagai sudah dibaca bila diklik
if (isset($_GET['read_notif'])) {
    markNotificationsRead($conn, $userId, $userRole);
    header("Location: pesanan-user.php");
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT b.*, k.id AS kos_id, k.name AS kos_name, k.address AS kos_address, k.type AS kos_type
     FROM bookings b
     JOIN kos k ON b.kos_id = k.id
     WHERE b.user_id = ? AND b.hidden_by_user = 0
     ORDER BY b.id DESC"
);
mysqli_stmt_bind_param($stmt, "i", $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$userBookings = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
mysqli_stmt_close($stmt);

// Pisahkan pesanan yang baru diajukan (pending) vs yang sudah dikonfirmasi/selesai/dibatalkan
$pendingUserBookings = [];
$processedUserBookings = [];
foreach ($userBookings as $ub) {
    $stVal = strtolower(trim((string)($ub['status'] ?? 'pending')));
    if ($stVal === 'confirmed' || $stVal === 'cancelled') {
        $processedUserBookings[] = $ub;
    } else {
        $pendingUserBookings[] = $ub;
    }
}

$userNotifications = getUserNotifications($conn, $userId, $userRole, 20);
$unreadCount = 0;
foreach ($userNotifications as $un) {
    if ((int)($un['is_read'] ?? 0) === 0) {
        $unreadCount++;
    }
}

$bulanIndo = [
    '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
    '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
    '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'
];

function renderUserBookingRows(array $rows, array $bulanIndo, string $emptyText, bool $canDeleteHistory = false): void
{
    $colCount = $canDeleteHistory ? 7 : 6;
    if (empty($rows)) {
        echo '<tr><td colspan="' . $colCount . '" class="py-8 text-center text-slate-400">' . $emptyText . '</td></tr>';
        return;
    }

    foreach ($rows as $b) {
        $rawWaktu = $b['created_at'] ?? $b['booked_at'] ?? '';
        $tsWaktu = !empty($rawWaktu) ? strtotime((string)$rawWaktu) : false;
        if ($tsWaktu !== false) {
            $tgl = date('d', $tsWaktu);
            $bln = $bulanIndo[date('m', $tsWaktu)] ?? date('M', $tsWaktu);
            $thn = date('Y', $tsWaktu);
            $jam = date('H:i', $tsWaktu);
            $waktuTampil = "{$tgl} {$bln} {$thn}, {$jam} WIB";
        } else {
            $waktuTampil = '-';
        }
        $targetKosId = (int)($b['kos_id'] ?? 0);
        $st = strtolower(trim((string)($b['status'] ?? 'pending')));
        $badge = $st === 'confirmed'
            ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
            : ($st === 'cancelled' ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200');
        ?>
        <tr class="hover:bg-slate-50/80">
            <td class="py-3.5 px-3 font-bold text-slate-900">
                <?php if ($targetKosId > 0): ?>
                    <a href="index.php?open_kos=<?= $targetKosId ?>"
                       title="Lihat detail kos"
                       class="font-bold text-slate-900 hover:text-teal-600 underline decoration-teal-500/40 underline-offset-2 transition inline-flex items-center gap-1">
                        <span><?= htmlspecialchars((string)($b['kos_name'] ?? '')) ?></span>
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-teal-600"></i>
                    </a>
                <?php else: ?>
                    <div><?= htmlspecialchars((string)($b['kos_name'] ?? '')) ?></div>
                <?php endif; ?>
                <div class="text-[11px] font-normal text-slate-500 mt-0.5">
                    <i class="fa-regular fa-clock text-teal-600 mr-1"></i><?= htmlspecialchars($waktuTampil) ?>
                </div>
            </td>
            <td class="py-3.5 px-3 text-slate-600"><?= htmlspecialchars((string)($b['kos_type'] ?? '')) ?></td>
            <td class="py-3.5 px-3 text-slate-600"><?= (int)$b['duration_months'] ?> Bulan</td>
            <td class="py-3.5 px-3 font-bold text-teal-700">Rp <?= number_format((int)$b['estimated_total'], 0, ',', '.') ?></td>
            <td class="py-3.5 px-3 text-slate-600"><?= htmlspecialchars((string)($b['checkin_date'] ?: '-')) ?></td>
            <td class="py-3.5 px-3">
                <span class="px-2.5 py-1 rounded-lg font-bold text-[11px] border <?= $badge ?>">
                    <?= strtoupper(htmlspecialchars($st)) ?>
                </span>
            </td>
            <?php if ($canDeleteHistory): ?>
                <td class="py-3.5 px-3 text-right whitespace-nowrap">
                    <form method="POST" action="pesanan-user.php" onsubmit="return confirm('Hapus riwayat pesanan ini dari tampilan akun Anda?');" class="inline">
                        <input type="hidden" name="post_action" value="delete_one_history">
                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                        <button type="submit" class="inline-block px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 font-bold cursor-pointer">
                            <i class="fa-solid fa-trash mr-1"></i> Hapus
                        </button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesanan User - KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 min-h-screen py-10 px-4">
    <div class="max-w-5xl mx-auto space-y-6">

        <!-- PANEL NOTIFIKASI USER -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-100">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-bell text-teal-600"></i>
                    <h2 class="text-sm sm:text-base font-extrabold text-slate-900">
                        Notifikasi Pesanan Saya
                        <?php if ($unreadCount > 0): ?>
                            <span class="ml-1.5 text-[11px] bg-emerald-600 text-white px-2 py-0.5 rounded-full"><?= $unreadCount ?> Baru</span>
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <?php if ($unreadCount > 0): ?>
                        <a href="pesanan-user.php?read_notif=1" class="text-xs font-bold text-teal-700 hover:underline">
                            Tandai semua dibaca
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($userNotifications)): ?>
                        <form method="POST" action="pesanan-user.php" onsubmit="return confirm('Hapus semua notifikasi pesanan saya?');" class="inline">
                            <input type="hidden" name="post_action" value="delete_all_notif">
                            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-bold transition cursor-pointer">
                                <i class="fa-solid fa-trash-can"></i> Hapus Semua Notif
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-3 space-y-2 text-xs">
                <?php if (empty($userNotifications)): ?>
                    <p class="text-slate-400 py-2">Belum ada notifikasi konfirmasi atau pembaruan pesanan.</p>
                <?php else: ?>
                    <?php foreach ($userNotifications as $nt): ?>
                        <?php $isUnread = ((int)($nt['is_read'] ?? 0) === 0); ?>
                        <div class="p-3 rounded-xl border flex flex-col sm:flex-row sm:items-center justify-between gap-2 <?= $isUnread ? 'bg-emerald-50/70 border-emerald-200' : 'bg-slate-50 border-slate-100' ?>">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-extrabold text-slate-900"><?= htmlspecialchars($nt['title']) ?></span>
                                    <?php if ($isUnread): ?>
                                        <span class="text-[10px] font-extrabold bg-emerald-600 text-white px-2 py-0.5 rounded-full">BARU</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-slate-600 mt-0.5"><?= htmlspecialchars($nt['message']) ?></p>
                            </div>
                            <div class="flex items-center gap-2.5 shrink-0">
                                <span class="text-[11px] text-slate-400">
                                    <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$nt['created_at']))) ?>
                                </span>
                                <form method="POST" action="pesanan-user.php" onsubmit="return confirm('Hapus notifikasi ini?');" class="inline">
                                    <input type="hidden" name="post_action" value="delete_one_notif">
                                    <input type="hidden" name="notification_id" value="<?= (int)$nt['id'] ?>">
                                    <button type="submit" title="Hapus notifikasi" class="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition cursor-pointer">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- HEADER & DAFTAR PESANAN USER -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-8">
            <div class="flex flex-wrap items-center justify-between gap-4 pb-5 border-b border-slate-100">
                <div>
                    <h1 class="text-xl font-extrabold text-slate-900">Pesanan User</h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Akun: <strong><?= htmlspecialchars($user['name']) ?></strong> &bull;
                        Total pengajuan: <strong><?= count($userBookings) ?></strong>
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <a href="index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-4 py-2 rounded-xl transition">
                        <i class="fa-solid fa-arrow-left mr-1"></i> Kembali ke Katalog
                    </a>
                    <a href="logout.php" class="bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 font-bold px-3.5 py-2 rounded-xl transition">
                        Logout
                    </a>
                </div>
            </div>

            <!-- BAGIAN 1: PESANAN BARU / MENUNGGU KONFIRMASI -->
            <div>
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                        Menunggu Konfirmasi
                    </span>
                    <h2 class="text-sm sm:text-base font-extrabold text-slate-900">
                        Kos yang Baru Anda Pesan (<?= count($pendingUserBookings) ?>)
                    </h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-3">Nama Kos & Waktu Pesan</th>
                                <th class="py-3 px-3">Tipe</th>
                                <th class="py-3 px-3">Durasi</th>
                                <th class="py-3 px-3">Estimasi Biaya</th>
                                <th class="py-3 px-3">Rencana Masuk</th>
                                <th class="py-3 px-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            renderUserBookingRows(
                                $pendingUserBookings,
                                $bulanIndo,
                                'Belum ada pesanan baru yang menunggu konfirmasi. Pilih kos di <a href="index.php" class="text-teal-600 font-bold underline">katalog</a> untuk memesan.',
                                false
                            );
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- BAGIAN 2: PESANAN SUDAH DIKONFIRMASI / SELESAI / DIBATALKAN -->
            <div class="pt-6 border-t border-slate-100">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                            Sudah Diproses
                        </span>
                        <h2 class="text-sm sm:text-base font-extrabold text-slate-900">
                            Pesanan Sudah Dikonfirmasi / Riwayat (<?= count($processedUserBookings) ?>)
                        </h2>
                    </div>
                    <?php if (!empty($processedUserBookings)): ?>
                        <form method="POST" action="pesanan-user.php" onsubmit="return confirm('Hapus semua riwayat pesanan pada akun Anda?');" class="inline">
                            <input type="hidden" name="post_action" value="delete_all_history">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-bold transition cursor-pointer">
                                <i class="fa-solid fa-trash-can"></i> Hapus Semua Riwayat
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-3">Nama Kos & Waktu Pesan</th>
                                <th class="py-3 px-3">Tipe</th>
                                <th class="py-3 px-3">Durasi</th>
                                <th class="py-3 px-3">Estimasi Biaya</th>
                                <th class="py-3 px-3">Rencana Masuk</th>
                                <th class="py-3 px-3">Status</th>
                                <th class="py-3 px-3 text-right">Hapus Riwayat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            renderUserBookingRows(
                                $processedUserBookings,
                                $bulanIndo,
                                'Belum ada pesanan yang sudah dikonfirmasi atau diproses.',
                                true
                            );
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</body>
</html>