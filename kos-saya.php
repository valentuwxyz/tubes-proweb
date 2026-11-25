<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

requireRole(['pemilik', 'admin'], 'login.php');
$user = currentUser();
$userId = (int)($user['id'] ?? 0);
$userRole = (string)($user['role'] ?? '');
$isOwner = ($userRole === 'pemilik');

$menuBadgeCount = ($userId > 0) ? getUnreadBadgeCount($conn, $userId, $userRole) : 0;

$hasOwnerId = false;
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM kos LIKE 'owner_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $hasOwnerId = true;
}

$flashMessage = '';
$flashType = 'success';

if ($isOwner && isset($_GET['delete_id'])) {
    $deleteId = (int)$_GET['delete_id'];

    if ($deleteId > 0 && $userId > 0) {
        if ($hasOwnerId) {
            $stmtCheck = mysqli_prepare($conn, "SELECT id, name FROM kos WHERE id = ? AND owner_id = ? LIMIT 1");
            if ($stmtCheck) {
                mysqli_stmt_bind_param($stmtCheck, "ii", $deleteId, $userId);
                mysqli_stmt_execute($stmtCheck);
                $resCheck = mysqli_stmt_get_result($stmtCheck);
                $targetKos = $resCheck ? mysqli_fetch_assoc($resCheck) : null;

                if ($targetKos) {
                    $tblRev = mysqli_query($conn, "SHOW TABLES LIKE 'reviews'");
                    if ($tblRev && mysqli_num_rows($tblRev) > 0) {
                        $stmtRev = mysqli_prepare($conn, "DELETE FROM reviews WHERE kos_id = ?");
                        if ($stmtRev) {
                            mysqli_stmt_bind_param($stmtRev, "i", $deleteId);
                            mysqli_stmt_execute($stmtRev);
                        }
                    }

                    $tblBook = mysqli_query($conn, "SHOW TABLES LIKE 'bookings'");
                    if ($tblBook && mysqli_num_rows($tblBook) > 0) {
                        $stmtBook = mysqli_prepare($conn, "DELETE FROM bookings WHERE kos_id = ?");
                        if ($stmtBook) {
                            mysqli_stmt_bind_param($stmtBook, "i", $deleteId);
                            mysqli_stmt_execute($stmtBook);
                        }
                    }

                    $stmtDel = mysqli_prepare($conn, "DELETE FROM kos WHERE id = ? AND owner_id = ?");
                    if ($stmtDel) {
                        mysqli_stmt_bind_param($stmtDel, "ii", $deleteId, $userId);
                        if (mysqli_stmt_execute($stmtDel) && mysqli_stmt_affected_rows($stmtDel) > 0) {
                            $flashMessage = 'Kos "' . htmlspecialchars($targetKos['name']) . '" berhasil dihapus karena sudah tidak Anda kelola.';
                            $flashType = 'success';
                        } else {
                            $flashMessage = 'Gagal menghapus data kos. Silakan coba lagi.';
                            $flashType = 'error';
                        }
                    }
                } else {
                    $flashMessage = 'Data kos tidak ditemukan atau bukan milik akun Anda.';
                    $flashType = 'error';
                }
            }
        } else {
            $flashMessage = 'Kolom owner_id belum tersedia pada tabel kos sehingga kepemilikan kos belum dapat diverifikasi.';
            $flashType = 'error';
        }
    }
}

$kosMilikSendiri = [];
if ($hasOwnerId) {
    $stmt = mysqli_prepare($conn, "SELECT * FROM kos WHERE owner_id = ? ORDER BY id DESC");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($res) {
            $kosMilikSendiri = mysqli_fetch_all($res, MYSQLI_ASSOC);
        }
    }
} else {
    $res = mysqli_query($conn, "SELECT * FROM kos ORDER BY id DESC");
    if ($res) {
        $kosMilikSendiri = mysqli_fetch_all($res, MYSQLI_ASSOC);
    }
}

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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kos Saya - KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html, body {
            max-width: 100%;
            overflow-x: clip;
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
    </style>
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
                    <span class="hidden md:block text-[10px] font-bold text-slate-400 uppercase tracking-wider -mt-1">Kelola Kos Saya</span>
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
                            <a href="kos-saya.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-emerald-700 bg-emerald-50/70 hover:bg-emerald-100 transition">
                                <i class="fa-solid fa-building-user w-4 text-center"></i>
                                <span>Kos Saya</span>
                            </a>
                            <a href="daftarkan-kos.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-teal-700 hover:bg-teal-50 transition">
                                <i class="fa-solid fa-plus-circle w-4 text-center"></i>
                                <span>Daftarkan Kos Baru</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($user['role'] === 'pemilik' || $user['role'] === 'admin'): ?>
                            <a href="pesanan.php" class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl font-bold text-indigo-700 hover:bg-indigo-50 transition">
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
        <div class="max-w-6xl mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-5 border-b border-slate-100">
                <div class="min-w-0">
                    <h1 class="text-lg sm:text-xl font-extrabold text-slate-900">Daftar Kos Yang Saya Daftarkan</h1>
                    <p class="text-xs text-slate-500 mt-1 leading-relaxed">
                        Pemilik: <strong class="text-slate-700"><?= htmlspecialchars($user['name']) ?></strong> (<?= htmlspecialchars($user['role']) ?>) &bull;
                        Total kos terdaftar: <strong class="text-teal-700"><?= count($kosMilikSendiri) ?></strong>
                    </p>
                </div>
                <div class="shrink-0">
                    <a href="daftarkan-kos.php" class="inline-flex items-center gap-1.5 bg-teal-600 hover:bg-teal-700 text-white font-bold px-3.5 py-2 rounded-xl shadow-sm transition text-xs whitespace-nowrap">
                        <i class="fa-solid fa-plus-circle"></i> Daftarkan Kos Baru
                    </a>
                </div>
            </div>

            <?php if ($flashMessage !== ''): ?>
                <div class="mt-4 px-4 py-3 rounded-xl text-xs font-bold border <?= $flashType === 'success' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-rose-50 text-rose-700 border-rose-200' ?>">
                    <?= $flashMessage ?>
                </div>
            <?php endif; ?>

            <?php if (!$hasOwnerId): ?>
                <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-800 text-xs px-4 py-3 rounded-xl">
                    Catatan: Kolom <code>owner_id</code> belum ada di tabel <code>kos</code>, sehingga daftar saat ini menampilkan seluruh data kos. Jalankan perintah <code>ALTER TABLE kos ADD COLUMN owner_id INT UNSIGNED DEFAULT NULL;</code> agar kos terfilter khusus per pemilik.
                </div>
            <?php endif; ?>

            <div class="overflow-x-auto w-full mt-5">
                <table class="w-full min-w-[640px] text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                            <th class="py-3 px-3">Nama Kos</th>
                            <th class="py-3 px-3">Tipe</th>
                            <th class="py-3 px-3">Jarak USU</th>
                            <th class="py-3 px-3">Tarif / Bulan</th>
                            <th class="py-3 px-3">Rating</th>
                            <th class="py-3 px-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($kosMilikSendiri)): ?>
                            <tr>
                                <td colspan="6" class="py-10 text-center text-slate-400 space-y-3">
                                    <p>Kamu belum mendaftarkan kos apa pun pada akun ini.</p>
                                    <div>
                                        <a href="daftarkan-kos.php" class="inline-block bg-teal-600 hover:bg-teal-700 text-white font-bold px-4 py-2 rounded-xl text-xs transition">
                                            <i class="fa-solid fa-plus-circle mr-1"></i> Daftarkan Kos Pertama
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($kosMilikSendiri as $k): ?>
                                <tr class="hover:bg-slate-50/80">
                                    <td class="py-3.5 px-3">
                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($k['name']) ?></div>
                                    </td>
                                    <td class="py-3.5 px-3 whitespace-nowrap">
                                        <span class="px-2.5 py-1 rounded-lg bg-teal-50 text-teal-700 border border-teal-200 font-bold text-[11px]">
                                            <?= htmlspecialchars($k['type']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-3 text-slate-600 whitespace-nowrap"><?= htmlspecialchars($k['distance']) ?></td>
                                    <td class="py-3.5 px-3 font-bold text-teal-700 whitespace-nowrap">
                                        Rp <?= number_format((int)$k['price'], 0, ',', '.') ?>
                                    </td>
                                    <td class="py-3.5 px-3 font-bold text-amber-500 whitespace-nowrap">
                                        <i class="fa-solid fa-star"></i> <?= number_format((float)$k['rating'], 1) ?>
                                    </td>
                                    <td class="py-3.5 px-3 text-right whitespace-nowrap space-x-1">
                                        <a href="edit-kos.php?id=<?= (int)$k['id'] ?>" class="inline-block px-3 py-1.5 rounded-lg bg-teal-50 hover:bg-teal-100 text-teal-700 border border-teal-200 font-bold transition">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                        </a>
                                        <a href="index.php?open_kos=<?= (int)$k['id'] ?>" class="inline-block px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">
                                            Lihat Detail
                                        </a>
                                        <?php if ($isOwner && $hasOwnerId): ?>
                                            <a href="kos-saya.php?delete_id=<?= (int)$k['id'] ?>"
                                               onclick="return confirm('Yakin ingin menghapus kos <?= htmlspecialchars(addslashes($k['name'])) ?> karena sudah tidak Anda kelola?');"
                                               class="inline-block px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 font-bold transition">
                                                <i class="fa-solid fa-trash mr-1"></i> Hapus
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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