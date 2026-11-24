<?php
require_once 'config/db.php';
require_once 'config/auth.php';

$currentUser = currentUser();
$targetId = (int)($_GET['id'] ?? 0);
$contextRole = trim((string)($_GET['role'] ?? ''));

if ($targetId <= 0) {
    header("Location: index.php");
    exit;
}

$targetUser = null;
$tblUsersRes = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
if ($tblUsersRes && mysqli_num_rows($tblUsersRes) > 0) {
    $userCols = [];
    $colUserRes = mysqli_query($conn, "SHOW COLUMNS FROM users");
    if ($colUserRes) {
        while ($uc = mysqli_fetch_assoc($colUserRes)) {
            $userCols[] = $uc['Field'];
        }
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $targetId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            $targetUser = mysqli_fetch_assoc($res);
        }
    }
}

$profileName = trim((string)($targetUser['name'] ?? $targetUser['username'] ?? $targetUser['full_name'] ?? ''));
if ($profileName === '') {
    $profileName = ($contextRole === 'pemilik') ? 'Pemilik Kos Terdaftar' : 'Pengguna KosFind';
}

$profileRole = trim((string)($targetUser['role'] ?? ($contextRole !== '' ? $contextRole : 'user')));
$profileEmail = trim((string)($targetUser['email'] ?? ''));
$profilePhone = trim((string)($targetUser['phone'] ?? $targetUser['whatsapp'] ?? $targetUser['no_hp'] ?? ''));
$profileBio = trim((string)($targetUser['bio'] ?? $targetUser['address'] ?? $targetUser['alamat'] ?? ''));

$rawAvatar = trim((string)($targetUser['avatar'] ?? $targetUser['photo'] ?? $targetUser['profile_photo'] ?? ''));
$profileAvatarSrc = '';
if ($rawAvatar !== '') {
    $sep = (strpos($rawAvatar, '?') !== false) ? '&' : '?';
    $cleanLocalPath = __DIR__ . '/' . ltrim(explode('?', $rawAvatar)[0], '/');
    $versionTag = is_file($cleanLocalPath) ? filemtime($cleanLocalPath) : time();
    $profileAvatarSrc = $rawAvatar . $sep . 'v=' . $versionTag;
}

$waDigits = preg_replace('/\D+/', '', $profilePhone);
if (strpos($waDigits, '0') === 0) {
    $waDigits = '62' . substr($waDigits, 1);
}
$waUrl = $waDigits !== '' ? "https://wa.me/{$waDigits}" : '';

// Jika yang dilihat adalah pemilik kos, tampilkan juga daftar kos miliknya bila ada
$ownerKosList = [];
$hasOwnerIdCol = false;
$colKosRes = mysqli_query($conn, "SHOW COLUMNS FROM kos LIKE 'owner_id'");
if ($colKosRes && mysqli_num_rows($colKosRes) > 0) {
    $hasOwnerIdCol = true;
}

if ($hasOwnerIdCol) {
    $stmtKos = mysqli_prepare($conn, "SELECT id, name, type, price, distance FROM kos WHERE owner_id = ? ORDER BY id DESC");
    if ($stmtKos) {
        mysqli_stmt_bind_param($stmtKos, "i", $targetId);
        mysqli_stmt_execute($stmtKos);
        $resKos = mysqli_stmt_get_result($stmtKos);
        if ($resKos) {
            $ownerKosList = mysqli_fetch_all($resKos, MYSQLI_ASSOC);
        }
    }
}

$backUrl = ($contextRole === 'user' && $currentUser && in_array($currentUser['role'], ['admin', 'pemilik'], true))
    ? 'pesanan.php'
    : 'index.php';
$backLabel = ($backUrl === 'pesanan.php') ? 'Kembali ke Pesanan Masuk' : 'Kembali ke Katalog Kos';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil <?= htmlspecialchars($profileName) ?> - KosFind</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen flex flex-col">
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 w-full shadow-xs">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3">
            <a href="index.php" class="flex items-center space-x-2 shrink-0">
                <div class="bg-teal-600 text-white p-2 rounded-xl font-bold text-base w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-house-chimney"></i>
                </div>
                <span class="text-base sm:text-lg font-black text-slate-900 tracking-tight">Kos<span class="text-teal-600">Find</span></span>
            </a>

            <a href="<?= htmlspecialchars($backUrl) ?>" class="inline-flex items-center gap-2 text-xs font-bold text-slate-700 hover:text-teal-700 bg-slate-100 hover:bg-teal-50 border border-slate-200 hover:border-teal-200 px-3.5 py-2 rounded-xl transition">
                <i class="fa-solid fa-arrow-left"></i>
                <span><?= htmlspecialchars($backLabel) ?></span>
            </a>
        </div>
    </header>

    <main class="flex-1 py-8 px-4 w-full">
        <div class="max-w-4xl mx-auto space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6 pb-6 border-b border-slate-100">
                    <div class="flex items-center gap-4 min-w-0">
                        <?php if ($profileAvatarSrc !== ''): ?>
                            <img
                                src="<?= htmlspecialchars($profileAvatarSrc) ?>"
                                alt="Foto <?= htmlspecialchars($profileName) ?>"
                                class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl object-cover border border-teal-200 shrink-0"
                                onerror="this.outerHTML='<div class=\'w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center shrink-0\'><i class=\'fa-solid fa-user text-2xl\'></i></div>';"
                            >
                        <?php else: ?>
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-teal-100 text-teal-700 flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-user text-2xl"></i>
                            </div>
                        <?php endif; ?>

                        <div class="min-w-0">
                            <span class="inline-block text-[11px] uppercase font-extrabold px-2.5 py-0.5 rounded-full bg-teal-50 text-teal-700 border border-teal-200">
                                <?= htmlspecialchars($profileRole) ?>
                            </span>
                            <h1 class="text-xl sm:text-2xl font-extrabold text-slate-900 truncate mt-1.5">
                                <?= htmlspecialchars($profileName) ?>
                            </h1>
                            <?php if ($profileEmail !== ''): ?>
                                <p class="text-xs text-slate-500 mt-1 truncate">
                                    <i class="fa-regular fa-envelope text-teal-600 mr-1.5"></i><?= htmlspecialchars($profileEmail) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($waUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-4 py-2.5 rounded-xl shadow-sm transition shrink-0">
                            <i class="fa-brands fa-whatsapp text-base"></i>
                            <span>Hubungi via WhatsApp</span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-6 text-xs">
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                        <span class="text-[11px] font-bold text-slate-400 uppercase block mb-1">Nomor Telepon / WA</span>
                        <span class="font-bold text-slate-800">
                            <?= $profilePhone !== '' ? htmlspecialchars($profilePhone) : 'Tidak dicantumkan' ?>
                        </span>
                    </div>
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                        <span class="text-[11px] font-bold text-slate-400 uppercase block mb-1">Status Peran</span>
                        <span class="font-bold text-slate-800 uppercase"><?= htmlspecialchars($profileRole) ?></span>
                    </div>
                </div>

                <?php if ($profileBio !== ''): ?>
                    <div class="mt-4 bg-slate-50 p-4 rounded-xl border border-slate-100 text-xs">
                        <span class="text-[11px] font-bold text-slate-400 uppercase block mb-1">Informasi / Bio</span>
                        <p class="text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($profileBio)) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($ownerKosList)): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-4">
                    <h2 class="text-base font-extrabold text-slate-900">
                        Kos yang Dikelola (<?= count($ownerKosList) ?>)
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <?php foreach ($ownerKosList as $kItem): ?>
                            <a href="index.php?open_kos=<?= (int)$kItem['id'] ?>"
                               class="p-4 rounded-xl border border-slate-200 hover:border-teal-500 hover:bg-teal-50/30 transition flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-bold text-slate-900 truncate"><?= htmlspecialchars($kItem['name']) ?></p>
                                    <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($kItem['type']) ?> &bull; <?= htmlspecialchars($kItem['distance']) ?></p>
                                </div>
                                <span class="font-extrabold text-teal-700 shrink-0">
                                    Rp <?= number_format((int)$kItem['price'], 0, ',', '.') ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>