<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

date_default_timezone_set('Asia/Jakarta');

requireLogin('login.php');
$user = currentUser();

if (!in_array($user['role'], ['admin', 'pemilik'], true)) {
    header("Location: index.php");
    exit;
}

$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
$isOwner = ($userRole === 'pemilik');

// Pastikan kolom visibility per role tersedia agar hapus riwayat terpisah antar pemilik, user, dan admin
function ensureBookingVisibilityColumns(mysqli $conn): void
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

ensureBookingVisibilityColumns($conn);

$hasOwnerId = false;
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM kos LIKE 'owner_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $hasOwnerId = true;
}

$flashMessage = '';
$flashType = 'success';

// Handler POST untuk hapus satu / hapus semua notifikasi & riwayat pemesanan (terpisah per role)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['post_action'] ?? '');

    if ($postAction === 'delete_one_notif') {
        $notifId = (int)($_POST['notification_id'] ?? 0);
        if ($notifId > 0 && deleteNotificationById($conn, $notifId, $userId, $userRole)) {
            $flashMessage = 'Notifikasi berhasil dihapus.';
        }
        header("Location: pesanan.php");
        exit;
    }

    if ($postAction === 'delete_all_notif') {
        if (deleteAllNotifications($conn, $userId, $userRole)) {
            $flashMessage = 'Semua notifikasi berhasil dihapus.';
        }
        header("Location: pesanan.php");
        exit;
    }

    if ($postAction === 'delete_one_history') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        if ($bookingId > 0) {
            if ($isOwner && $hasOwnerId) {
                $stmtDel = mysqli_prepare(
                    $conn,
                    "UPDATE bookings b
                     JOIN kos k ON b.kos_id = k.id
                     SET b.hidden_by_owner = 1
                     WHERE b.id = ? AND k.owner_id = ? AND LOWER(b.status) IN ('confirmed', 'cancelled')"
                );
                if ($stmtDel) {
                    mysqli_stmt_bind_param($stmtDel, "ii", $bookingId, $userId);
                    mysqli_stmt_execute($stmtDel);
                    mysqli_stmt_close($stmtDel);
                    $flashMessage = 'Riwayat pesanan pada akun pemilik berhasil dihapus.';
                }
            } elseif ($userRole === 'admin') {
                $stmtDel = mysqli_prepare(
                    $conn,
                    "UPDATE bookings SET hidden_by_admin = 1 WHERE id = ? AND LOWER(status) IN ('confirmed', 'cancelled')"
                );
                if ($stmtDel) {
                    mysqli_stmt_bind_param($stmtDel, "i", $bookingId);
                    mysqli_stmt_execute($stmtDel);
                    mysqli_stmt_close($stmtDel);
                    $flashMessage = 'Riwayat pesanan pada akun admin berhasil dihapus.';
                }
            }
        }
        header("Location: pesanan.php");
        exit;
    }

    if ($postAction === 'delete_all_history') {
        if ($isOwner && $hasOwnerId) {
            $stmtDelAll = mysqli_prepare(
                $conn,
                "UPDATE bookings b
                 JOIN kos k ON b.kos_id = k.id
                 SET b.hidden_by_owner = 1
                 WHERE k.owner_id = ? AND LOWER(b.status) IN ('confirmed', 'cancelled')"
            );
            if ($stmtDelAll) {
                mysqli_stmt_bind_param($stmtDelAll, "i", $userId);
                mysqli_stmt_execute($stmtDelAll);
                mysqli_stmt_close($stmtDelAll);
                $flashMessage = 'Semua riwayat pesanan pada akun pemilik berhasil dihapus.';
            }
        } elseif ($userRole === 'admin') {
            mysqli_query($conn, "UPDATE bookings SET hidden_by_admin = 1 WHERE LOWER(status) IN ('confirmed', 'cancelled')");
            $flashMessage = 'Semua riwayat pesanan pada akun admin berhasil dihapus.';
        }
        header("Location: pesanan.php");
        exit;
    }
}

if (isset($_GET['read_notif'])) {
    markNotificationsRead($conn, $userId, $userRole);
    header("Location: pesanan.php");
    exit;
}

if ($isOwner && isset($_GET['action'], $_GET['id'])) {
    $bookingId = (int)$_GET['id'];
    $action = $_GET['action'];
    $newStatus = ($action === 'confirm') ? 'confirmed' : (($action === 'cancel') ? 'cancelled' : null);

    if ($bookingId > 0 && $newStatus !== null) {
        $sqlFetch = "SELECT b.*, k.name AS kos_name, k.owner_id
                     FROM bookings b
                     JOIN kos k ON b.kos_id = k.id
                     WHERE b.id = ?" . ($hasOwnerId ? " AND k.owner_id = ?" : "") . " LIMIT 1";
        $stmtFetch = mysqli_prepare($conn, $sqlFetch);
        $bookingRow = null;
        if ($stmtFetch) {
            if ($hasOwnerId) {
                mysqli_stmt_bind_param($stmtFetch, "ii", $bookingId, $userId);
            } else {
                mysqli_stmt_bind_param($stmtFetch, "i", $bookingId);
            }
            mysqli_stmt_execute($stmtFetch);
            $resFetch = mysqli_stmt_get_result($stmtFetch);
            $bookingRow = $resFetch ? mysqli_fetch_assoc($resFetch) : null;
            mysqli_stmt_close($stmtFetch);
        }

        if ($bookingRow) {
            if ($hasOwnerId) {
                $sqlUpd = "UPDATE bookings b
                           JOIN kos k ON b.kos_id = k.id
                           SET b.status = ?
                           WHERE b.id = ? AND k.owner_id = ?";
                $stmtUpd = mysqli_prepare($conn, $sqlUpd);
                if ($stmtUpd) {
                    mysqli_stmt_bind_param($stmtUpd, "sii", $newStatus, $bookingId, $userId);
                    mysqli_stmt_execute($stmtUpd);
                    mysqli_stmt_close($stmtUpd);
                }
            } else {
                $stmtUpd = mysqli_prepare($conn, "UPDATE bookings SET status = ? WHERE id = ?");
                if ($stmtUpd) {
                    mysqli_stmt_bind_param($stmtUpd, "si", $newStatus, $bookingId);
                    mysqli_stmt_execute($stmtUpd);
                    mysqli_stmt_close($stmtUpd);
                }
            }

            if ($newStatus === 'confirmed') {
                $tenantUserId = isset($bookingRow['user_id']) ? (int)$bookingRow['user_id'] : null;
                $tenantNameStr = (string)($bookingRow['tenant_name'] ?? $bookingRow['customer_name'] ?? '');
                notifyBookingConfirmedToUser(
                    $conn,
                    $bookingId,
                    (int)$bookingRow['kos_id'],
                    (string)($bookingRow['kos_name'] ?? 'KosFind'),
                    (string)($user['name'] ?? 'Pemilik Kos'),
                    $tenantUserId > 0 ? $tenantUserId : null,
                    $tenantNameStr
                );
                $flashMessage = 'Pesanan dikonfirmasi dan notifikasi telah dikirimkan ke user.';
            } elseif ($newStatus === 'cancelled') {
                $flashMessage = 'Pesanan telah dibatalkan.';
            }
        }
    }
    header("Location: pesanan.php");
    exit;
}

$bookings = [];
if ($isOwner && $hasOwnerId) {
    $sql = "SELECT b.*, k.id AS kos_id, k.name AS kos_name, k.price AS kos_price
            FROM bookings b
            JOIN kos k ON b.kos_id = k.id
            WHERE k.owner_id = ? AND b.hidden_by_owner = 0
            ORDER BY b.id DESC";
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            $bookings = mysqli_fetch_all($res, MYSQLI_ASSOC);
        }
        mysqli_stmt_close($stmt);
    }
} else {
    $sql = "SELECT b.*, k.id AS kos_id, k.name AS kos_name, k.price AS kos_price
            FROM bookings b
            JOIN kos k ON b.kos_id = k.id
            WHERE b.hidden_by_admin = 0
            ORDER BY b.id DESC";
    $res = mysqli_query($conn, $sql);
    $bookings = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

$newPendingBookings = [];
$completedOrConfirmedBookings = [];
foreach ($bookings as $bk) {
    $stVal = strtolower(trim((string)($bk['status'] ?? 'pending')));
    if ($stVal === 'confirmed' || $stVal === 'cancelled') {
        $completedOrConfirmedBookings[] = $bk;
    } else {
        $newPendingBookings[] = $bk;
    }
}

$notifications = getUserNotifications($conn, $userId, $userRole, 20);
$unreadCount = getUnreadBadgeCount($conn, $userId, $userRole);
$menuBadgeCount = max($unreadCount, count($newPendingBookings));

$bulanIndo = [
    '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
    '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
    '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'
];

$avatarSrc = '';
if ($user && is_array($user)) {
    $rawAvatar = trim((string)($user['avatar'] ?? $user['photo'] ?? $user['profile_photo'] ?? ($_SESSION['avatar'] ?? '')));
    if ($rawAvatar !== '') {
        $sep = (strpos($rawAvatar, '?') !== false) ? '&' : '?';
        $cleanLocalPath = __DIR__ . '/' . ltrim(explode('?', $rawAvatar)[0], '/');
        $versionTag = is_file($cleanLocalPath) ? filemtime($cleanLocalPath) : time();
        $avatarSrc = $rawAvatar . $sep . 'v=' . $versionTag;
    }
}

function renderBookingRows(array $rows, bool $isOwner, bool $canDelete, array $bulanIndo, bool $showConfirmActions): void
{
    if (empty($rows)) {
        $colCount = 6 + ($showConfirmActions ? 1 : 0) + ($canDelete ? 1 : 0);
        echo '<tr><td colspan="' . $colCount . '" class="py-8 text-center text-slate-400">Tidak ada data pada bagian ini.</td></tr>';
        return;
    }

    foreach ($rows as $b) {
        $namaPemesan  = $b['tenant_name'] ?? $b['customer_name'] ?? 'Kak';
        $targetUserId = (int)($b['user_id'] ?? $b['tenant_id'] ?? $b['customer_id'] ?? 0);
        $noWaRaw      = $b['tenant_phone'] ?? $b['tenant_whatsapp'] ?? $b['tenant_wa'] ?? $b['customer_whatsapp'] ?? $b['whatsapp'] ?? '';
        $namaKos      = $b['kos_name'] ?? 'KosFind USU';
        $durasiSewa   = $b['duration_months'] ?? $b['duration'] ?? '1';
        $targetKosId  = (int)($b['kos_id'] ?? 0);

        $waDigits = preg_replace('/\D+/', '', (string)$noWaRaw);
        if (strpos($waDigits, '0') === 0) {
            $waDigits = '62' . substr($waDigits, 1);
        }

        $teksWa = "Halo {$namaPemesan}, terkait pesanan sewa kamar di *{$namaKos}* selama *{$durasiSewa} Bulan* di KosFind USU. Kapan rencana survei atau mulai masuk kamar?";
        $urlWa  = $waDigits !== '' ? "https://wa.me/{$waDigits}?text=" . rawurlencode($teksWa) : '';

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

        $st = strtolower(trim((string)($b['status'] ?? 'pending')));
        $badge = $st === 'confirmed'
            ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
            : ($st === 'cancelled' ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-amber-50 text-amber-700 border-amber-200');
        ?>
        <tr class="hover:bg-slate-50/80">
            <td class="py-3.5 px-3 font-bold text-slate-900 whitespace-nowrap">
                <?php if ($targetUserId > 0): ?>
                    <a href="lihat-profil.php?id=<?= $targetUserId ?>&role=user"
                       title="Lihat profil pemesan"
                       class="font-bold text-slate-900 hover:text-teal-600 underline decoration-teal-500/40 underline-offset-2 transition inline-flex items-center gap-1">
                        <span><?= htmlspecialchars($namaPemesan) ?></span>
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-teal-600"></i>
                    </a>
                <?php else: ?>
                    <?= htmlspecialchars($namaPemesan) ?>
                <?php endif; ?>
            </td>
            <td class="py-3.5 px-3 whitespace-nowrap">
                <div class="font-medium text-slate-600"><?= htmlspecialchars((string)$noWaRaw) ?></div>
                <?php if ($urlWa !== ''): ?>
                    <a href="<?= htmlspecialchars($urlWa) ?>" target="_blank" rel="noopener noreferrer"
                       class="mt-1 inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold px-2.5 py-1 rounded-lg text-[11px] transition">
                        <i class="fa-brands fa-whatsapp"></i> Chat Pemesan
                    </a>
                <?php endif; ?>
            </td>
            <td class="py-3.5 px-3 font-semibold text-slate-800">
                <?php if ($targetKosId > 0): ?>
                    <a href="index.php?open_kos=<?= $targetKosId ?>"
                       title="Lihat detail kos"
                       class="font-bold text-slate-900 hover:text-teal-600 underline decoration-teal-500/40 underline-offset-2 transition inline-flex items-center gap-1">
                        <span><?= htmlspecialchars($namaKos) ?></span>
                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-teal-600"></i>
                    </a>
                <?php else: ?>
                    <div><?= htmlspecialchars($namaKos) ?></div>
                <?php endif; ?>
                <div class="text-[11px] font-normal text-slate-500 mt-0.5">
                    <i class="fa-regular fa-clock text-teal-600 mr-1"></i><?= htmlspecialchars($waktuTampil) ?>
                </div>
            </td>
            <td class="py-3.5 px-3 text-slate-600 whitespace-nowrap"><?= (int)$durasiSewa ?> Bulan</td>
            <td class="py-3.5 px-3 font-bold text-teal-700 whitespace-nowrap">Rp <?= number_format((int)$b['estimated_total'], 0, ',', '.') ?></td>
            <td class="py-3.5 px-3 whitespace-nowrap">
                <span class="px-2.5 py-1 rounded-lg font-bold text-[11px] border <?= $badge ?>">
                    <?= strtoupper(htmlspecialchars($st)) ?>
                </span>
            </td>
            <?php if ($showConfirmActions): ?>
                <td class="py-3.5 px-3 text-right whitespace-nowrap space-x-1">
                    <a href="pesanan.php?action=confirm&id=<?= (int)$b['id'] ?>"
                       onclick="return confirm('Konfirmasi pesanan dari <?= htmlspecialchars(addslashes($namaPemesan)) ?>?');"
                       class="inline-block px-2.5 py-1.5 rounded-lg bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 font-bold">Konfirmasi</a>
                    <a href="pesanan.php?action=cancel&id=<?= (int)$b['id'] ?>"
                       onclick="return confirm('Batalkan pesanan dari <?= htmlspecialchars(addslashes($namaPemesan)) ?>?');"
                       class="inline-block px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 font-bold">Batalkan</a>
                </td>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <td class="py-3.5 px-3 text-right whitespace-nowrap">
                    <form method="POST" action="pesanan.php" onsubmit="return confirm('Hapus riwayat pesanan <?= htmlspecialchars(addslashes($namaPemesan)) ?> dari tampilan akun ini?');" class="inline">
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
    <title>Daftar Pesanan Kos - KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen flex flex-col">
    <!-- NAVBAR GARIS TIGA -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 w-full">
        <div class="max-w-6xl mx-auto px-3 sm:px-6 h-16 flex items-center justify-between gap-3">
            <a href="index.php" class="flex items-center space-x-2 shrink-0">
                <div class="bg-teal-600 text-white p-2 rounded-xl font-bold text-base w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-house-chimney"></i>
                </div>
                <div>
                    <span class="text-base sm:text-lg font-black text-slate-900 tracking-tight">Kos<span class="text-teal-600">Find</span></span>
                    <span class="hidden md:block text-[10px] font-bold text-slate-400 uppercase tracking-wider -mt-1">Kelola Pesanan Masuk</span>
                </div>
            </a>

            <div class="relative shrink-0" id="navMenuWrapper">
                <button
                    type="button"
                    id="navMenuToggle"
                    onclick="toggleNavMenu(event)"
                    aria-expanded="false"
                    aria-controls="navDropdownList"
                    aria-label="Buka menu navigasi"
                    class="inline-flex items-center gap-2 bg-slate-100 hover:bg-teal-50 text-slate-700 hover:text-teal-700 border border-slate-200 hover:border-teal-200 px-3.5 py-2 rounded-xl text-xs font-bold shadow-sm transition cursor-pointer"
                >
                    <i class="fa-solid fa-bars text-sm"></i>
                    <span>Menu</span>
                    <?php if ($menuBadgeCount > 0): ?>
                        <span class="bg-rose-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full leading-none">
                            +<?= $menuBadgeCount ?>
                        </span>
                    <?php endif; ?>
                    <i id="navMenuChevron" class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"></i>
                </button>

                <div
                    id="navDropdownList"
                    class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-slate-200 py-2 z-50 text-xs"
                >
                    <div class="px-3.5 py-2.5 border-b border-slate-100 flex items-center gap-2.5 bg-slate-50/70">
                        <?php if ($avatarSrc !== ''): ?>
                            <img
                                src="<?= htmlspecialchars($avatarSrc) ?>"
                                alt="Foto <?= htmlspecialchars($user['name']) ?>"
                                class="w-8 h-8 rounded-full object-cover border border-teal-200 shrink-0"
                                onerror="this.outerHTML='<div class=\'w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center shrink-0\'><i class=\'fa-solid fa-circle-user text-base\'></i></div>';"
                            >
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-circle-user text-base"></i>
                            </div>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <p class="font-extrabold text-slate-900 truncate"><?= htmlspecialchars($user['name']) ?></p>
                            <span class="inline-block text-[10px] uppercase font-extrabold px-1.5 py-0.5 rounded bg-white text-teal-700 border border-teal-200 mt-0.5">
                                <?= htmlspecialchars($user['role']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-1.5 space-y-1">
                        <a href="index.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-700 hover:bg-slate-100 transition">
                            <i class="fa-solid fa-house w-4 text-center text-teal-600"></i>
                            <span>Katalog</span>
                        </a>
                        <?php if ($user['role'] === 'pemilik'): ?>
                            <a href="kos-saya.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-emerald-700 hover:bg-emerald-50 transition">
                                <i class="fa-solid fa-building-user w-4 text-center"></i>
                                <span>Kos Saya</span>
                            </a>
                            <a href="daftarkan-kos.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-teal-700 hover:bg-teal-50 transition">
                                <i class="fa-solid fa-plus-circle w-4 text-center"></i>
                                <span>Daftarkan Kos Baru</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($user['role'] === 'pemilik' || $user['role'] === 'admin'): ?>
                            <a href="pesanan.php" class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl font-bold text-indigo-700 bg-indigo-50/70 hover:bg-indigo-100 transition">
                                <span class="flex items-center gap-2.5">
                                    <i class="fa-solid fa-receipt w-4 text-center"></i>
                                    <span>Pesanan Masuk</span>
                                </span>
                                <?php if ($menuBadgeCount > 0): ?>
                                    <span class="bg-rose-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full leading-none">
                                        +<?= $menuBadgeCount ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($user['role'] === 'admin'): ?>
                            <a href="admin/index.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-amber-700 hover:bg-amber-50 transition">
                                <i class="fa-solid fa-sliders w-4 text-center"></i>
                                <span>CMS Admin</span>
                            </a>
                        <?php endif; ?>

                        <a href="profil.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-700 hover:bg-slate-100 transition">
                            <i class="fa-solid fa-user-pen w-4 text-center text-slate-500"></i>
                            <span>Profil</span>
                        </a>
                    </div>

                    <div class="p-1.5 pt-1 border-t border-slate-100">
                        <a href="logout.php" onclick="return confirm('Yakin ingin keluar dari akun?');" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-rose-600 hover:bg-rose-50 transition">
                            <i class="fa-solid fa-right-from-bracket w-4 text-center"></i>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="flex-1 py-6 sm:py-10 px-3 sm:px-4 w-full">
        <div class="max-w-6xl mx-auto space-y-6">

            <?php if ($flashMessage !== ''): ?>
                <div class="px-4 py-3 rounded-xl text-xs font-bold border <?= $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                    <?= htmlspecialchars($flashMessage) ?>
                </div>
            <?php endif; ?>

            <!-- PANEL NOTIFIKASI MASUK -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-bell text-teal-600"></i>
                        <h2 class="text-sm sm:text-base font-extrabold text-slate-900">
                            Notifikasi Masuk
                            <?php if ($unreadCount > 0): ?>
                                <span class="ml-1.5 text-[11px] bg-rose-600 text-white px-2 py-0.5 rounded-full">+<?= $unreadCount ?></span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <?php if ($unreadCount > 0): ?>
                            <a href="pesanan.php?read_notif=1" class="text-xs font-bold text-teal-700 hover:underline">
                                Tandai semua dibaca
                            </a>
                        <?php endif; ?>
                        <?php if (!empty($notifications)): ?>
                            <form method="POST" action="pesanan.php" onsubmit="return confirm('Hapus semua notifikasi masuk?');" class="inline">
                                <input type="hidden" name="post_action" value="delete_all_notif">
                                <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-bold transition cursor-pointer">
                                    <i class="fa-solid fa-trash-can"></i> Hapus Semua Notif
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-3 space-y-2 text-xs">
                    <?php if (empty($notifications)): ?>
                        <p class="text-slate-400 py-2">Belum ada notifikasi pesanan masuk.</p>
                    <?php else: ?>
                        <?php foreach ($notifications as $nt): ?>
                            <?php $isUnread = ((int)($nt['is_read'] ?? 0) === 0); ?>
                            <div class="p-3 rounded-xl border flex flex-col sm:flex-row sm:items-center justify-between gap-2 <?= $isUnread ? 'bg-teal-50/60 border-teal-200' : 'bg-slate-50 border-slate-100' ?>">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-extrabold text-slate-900"><?= htmlspecialchars($nt['title']) ?></span>
                                        <?php if ($isUnread): ?>
                                            <span class="text-[10px] font-extrabold bg-teal-600 text-white px-2 py-0.5 rounded-full">+1 BARU</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-slate-600 mt-0.5"><?= htmlspecialchars($nt['message']) ?></p>
                                </div>
                                <div class="flex items-center gap-2.5 shrink-0">
                                    <span class="text-[11px] text-slate-400">
                                        <?= htmlspecialchars(date('d M Y H:i', strtotime((string)$nt['created_at']))) ?>
                                    </span>
                                    <form method="POST" action="pesanan.php" onsubmit="return confirm('Hapus notifikasi ini?');" class="inline">
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

            <!-- BAGIAN 1: PESANAN BARU / BELUM DIKONFIRMASI -->
            <div class="bg-white rounded-2xl border border-amber-200 shadow-sm p-4 sm:p-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-100">
                    <div>
                        <span class="inline-block text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 mb-1">
                            Perlu Tindakan
                        </span>
                        <h1 class="text-lg sm:text-xl font-extrabold text-slate-900 flex items-center gap-2">
                            <span>Pesanan Masuk Baru (<?= count($newPendingBookings) ?>)</span>
                            <?php if (count($newPendingBookings) > 0): ?>
                                <span class="inline-block bg-rose-600 text-white text-[11px] font-extrabold px-2 py-0.5 rounded-full">
                                    +<?= count($newPendingBookings) ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="text-xs text-slate-500 mt-0.5">
                            User yang baru saja memesan kos dan menunggu konfirmasi dari pemilik.
                        </p>
                    </div>
                </div>

                <div class="overflow-x-auto w-full mt-4">
                    <table class="w-full min-w-[640px] text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-3">Pemesan</th>
                                <th class="py-3 px-3">No. WA</th>
                                <th class="py-3 px-3">Nama Kos & Waktu Pesan</th>
                                <th class="py-3 px-3">Durasi</th>
                                <th class="py-3 px-3">Estimasi</th>
                                <th class="py-3 px-3">Status</th>
                                <?php if ($isOwner): ?>
                                    <th class="py-3 px-3 text-right">Aksi Pemilik</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php renderBookingRows($newPendingBookings, $isOwner, false, $bulanIndo, $isOwner); ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- BAGIAN 2: PESANAN SUDAH DIKONFIRMASI / SELESAI / DIBATALKAN -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-8">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-100">
                    <div>
                        <span class="inline-block text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 mb-1">
                            Diproses / Riwayat
                        </span>
                        <h2 class="text-lg font-extrabold text-slate-900">
                            Pesanan Sudah Dikonfirmasi / Selesai (<?= count($completedOrConfirmedBookings) ?>)
                        </h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Menghapus riwayat di sini hanya menyembunyikannya dari akun ini, tidak menghapus riwayat milik user atau admin.
                        </p>
                    </div>
                    <?php if (($isOwner || $userRole === 'admin') && !empty($completedOrConfirmedBookings)): ?>
                        <form method="POST" action="pesanan.php" onsubmit="return confirm('Hapus semua riwayat pesanan pada akun ini?');" class="shrink-0">
                            <input type="hidden" name="post_action" value="delete_all_history">
                            <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-bold transition cursor-pointer">
                                <i class="fa-solid fa-trash-can"></i> Hapus Semua Riwayat
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="overflow-x-auto w-full mt-4">
                    <table class="w-full min-w-[640px] text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                                <th class="py-3 px-3">Pemesan</th>
                                <th class="py-3 px-3">No. WA</th>
                                <th class="py-3 px-3">Nama Kos & Waktu Pesan</th>
                                <th class="py-3 px-3">Durasi</th>
                                <th class="py-3 px-3">Estimasi</th>
                                <th class="py-3 px-3">Status</th>
                                <?php if ($isOwner || $userRole === 'admin'): ?>
                                    <th class="py-3 px-3 text-right">Hapus Riwayat</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php renderBookingRows($completedOrConfirmedBookings, $isOwner, ($isOwner || $userRole === 'admin'), $bulanIndo, false); ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        function toggleNavMenu(event) {
            if (event) event.stopPropagation();
            const dropdown = document.getElementById('navDropdownList');
            const toggleBtn = document.getElementById('navMenuToggle');
            const chevron = document.getElementById('navMenuChevron');
            if (!dropdown) return;

            const isHidden = dropdown.classList.contains('hidden');
            if (isHidden) {
                dropdown.classList.remove('hidden');
                if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
                if (chevron) chevron.classList.add('rotate-180');
            } else {
                dropdown.classList.add('hidden');
                if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
                if (chevron) chevron.classList.remove('rotate-180');
            }
        }

        function closeNavMenu() {
            const dropdown = document.getElementById('navDropdownList');
            const toggleBtn = document.getElementById('navMenuToggle');
            const chevron = document.getElementById('navMenuChevron');
            if (dropdown && !dropdown.classList.contains('hidden')) {
                dropdown.classList.add('hidden');
                if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
                if (chevron) chevron.classList.remove('rotate-180');
            }
        }

        document.addEventListener('click', function (e) {
            const wrapper = document.getElementById('navMenuWrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                closeNavMenu();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeNavMenu();
            }
        });
    </script>
</body>
</html>