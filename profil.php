<?php
require_once 'config/db.php';
require_once 'config/auth.php';

requireLogin('login.php');
$sessionUser = currentUser();
$userId = (int)($sessionUser['id'] ?? 0);

if ($userId <= 0) {
    header("Location: login.php");
    exit;
}

// Ambil data terbaru user dari database
$stmtUser = mysqli_prepare(
    $conn,
    "SELECT id, name, username, email, password, role, phone, avatar, created_at
     FROM users
     WHERE id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmtUser, "i", $userId);
mysqli_stmt_execute($stmtUser);
$resUser = mysqli_stmt_get_result($stmtUser);
$dbUser = $resUser ? mysqli_fetch_assoc($resUser) : null;

if (!$dbUser) {
    header("Location: logout.php");
    exit;
}

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? 'update_profile';

    if ($formAction === 'update_profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        // Rapikan spasi berlebih di tengah dan hapus spasi di awal/akhir
        $usernameRaw = preg_replace('/\s+/', ' ', trim((string)($_POST['username'] ?? '')));
        $username = $usernameRaw;
        $phone = trim((string)($_POST['phone'] ?? ''));

        if ($name === '') {
            $errorMsg = 'Nama lengkap wajib diisi.';
        } elseif ($username !== '' && !preg_match('/^[A-Za-z0-9._ -]{3,30}$/', $username)) {
            $errorMsg = 'Username hanya boleh berisi huruf, angka, spasi, titik (.), underscore (_), atau tanda hubung (-) sepanjang 3–30 karakter.';
        } else {
            // Cek keunikan username jika diisi
            if ($username !== '') {
                $stmtCek = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
                mysqli_stmt_bind_param($stmtCek, "si", $username, $userId);
                mysqli_stmt_execute($stmtCek);
                $resCek = mysqli_stmt_get_result($stmtCek);
                if ($resCek && mysqli_num_rows($resCek) > 0) {
                    $errorMsg = "Username \"{$username}\" sudah digunakan oleh akun lain.";
                }
            }

            $newAvatarPath = (string)($dbUser['avatar'] ?? '');
            $uploadedNewFile = false;

            // Proses upload foto profil dari galeri / file explorer jika ada file dipilih
            if ($errorMsg === '' && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    $errorMsg = 'Gagal mengunggah foto profil. Silakan coba pilih gambar lagi.';
                } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
                    $errorMsg = 'Ukuran foto profil maksimal 2 MB.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($_FILES['avatar']['tmp_name']);
                    $allowedMimes = [
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/webp' => 'webp'
                    ];

                    if (!isset($allowedMimes[$mime])) {
                        $errorMsg = 'Format foto profil harus JPG, PNG, atau WEBP.';
                    } else {
                        $avatarDir = __DIR__ . '/uploads/avatars';
                        if (!is_dir($avatarDir)) {
                            mkdir($avatarDir, 0755, true);
                        }

                        $ext = $allowedMimes[$mime];
                        $fileName = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        $targetFile = $avatarDir . '/' . $fileName;

                        if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetFile)) {
                            $newAvatarPath = 'uploads/avatars/' . $fileName;
                            $uploadedNewFile = true;
                        } else {
                            $errorMsg = 'Gagal menyimpan file foto ke folder uploads/avatars.';
                        }
                    }
                }
            }

            if ($errorMsg === '') {
                $usernameParam = $username !== '' ? $username : null;
                $phoneParam = $phone !== '' ? $phone : null;
                $avatarParam = $newAvatarPath !== '' ? $newAvatarPath : null;

                $stmtUpd = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET name = ?, username = ?, phone = ?, avatar = ?
                     WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmtUpd, "ssssi", $name, $usernameParam, $phoneParam, $avatarParam, $userId);

                if (mysqli_stmt_execute($stmtUpd)) {
                    // Hapus file avatar lama jika berhasil upload avatar baru dan path-nya aman di uploads/avatars/
                    $oldAvatar = (string)($dbUser['avatar'] ?? '');
                    if ($uploadedNewFile && $oldAvatar !== '' && strpos($oldAvatar, 'uploads/avatars/') === 0 && $oldAvatar !== $newAvatarPath) {
                        $oldFullPath = __DIR__ . '/' . ltrim(explode('?', $oldAvatar)[0], '/');
                        if (is_file($oldFullPath)) {
                            @unlink($oldFullPath);
                        }
                    }

                    // Sinkronkan data di session bila menggunakan array $_SESSION['user'] atau $_SESSION['avatar']
                    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['name'] = $name;
                        $_SESSION['user']['username'] = $usernameParam;
                        $_SESSION['user']['phone'] = $phoneParam;
                        $_SESSION['user']['avatar'] = $avatarParam;
                    }
                    $_SESSION['avatar'] = $avatarParam;

                    $dbUser['name'] = $name;
                    $dbUser['username'] = $usernameParam;
                    $dbUser['phone'] = $phoneParam;
                    $dbUser['avatar'] = $avatarParam;
                    $successMsg = 'Profil berhasil diperbarui.';
                } else {
                    if ($uploadedNewFile && $newAvatarPath !== '') {
                        @unlink(__DIR__ . '/' . $newAvatarPath);
                    }
                    $errorMsg = 'Gagal memperbarui profil: ' . mysqli_error($conn);
                }
            }
        }
    } elseif ($formAction === 'change_password') {
        $currentPass = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');

        if ($currentPass === '' || $newPass === '' || $confirmPass === '') {
            $errorMsg = 'Semua kolom kata sandi wajib diisi.';
        } elseif (!password_verify($currentPass, (string)$dbUser['password'])) {
            $errorMsg = 'Kata sandi saat ini tidak sesuai.';
        } elseif (strlen($newPass) < 6) {
            $errorMsg = 'Kata sandi baru minimal 6 karakter.';
        } elseif ($newPass !== $confirmPass) {
            $errorMsg = 'Konfirmasi kata sandi baru tidak cocok.';
        } else {
            $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
            $stmtPass = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmtPass, "si", $hashedPass, $userId);
            if (mysqli_stmt_execute($stmtPass)) {
                $dbUser['password'] = $hashedPass;
                $successMsg = 'Kata sandi berhasil diperbarui.';
            } else {
                $errorMsg = 'Gagal memperbarui kata sandi: ' . mysqli_error($conn);
            }
        }
    }
}

// Ringkasan singkat sesuai role
$role = (string)($dbUser['role'] ?? 'user');
$summaryCount1 = 0;
$summaryLabel1 = '';

if ($role === 'pemilik') {
    $summaryLabel1 = 'Kos Terdaftar';
    $colOwner = mysqli_query($conn, "SHOW COLUMNS FROM kos LIKE 'owner_id'");
    if ($colOwner && mysqli_num_rows($colOwner) > 0) {
        $stCnt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM kos WHERE owner_id = ?");
        mysqli_stmt_bind_param($stCnt, "i", $userId);
        mysqli_stmt_execute($stCnt);
        $rCnt = mysqli_stmt_get_result($stCnt);
        if ($rCnt && $rowCnt = mysqli_fetch_assoc($rCnt)) {
            $summaryCount1 = (int)$rowCnt['cnt'];
        }
    }
} elseif ($role === 'user') {
    $summaryLabel1 = 'Pengajuan Sewa';
    $stCnt = mysqli_prepare($conn, "SELECT COUNT(*) AS cnt FROM bookings WHERE user_id = ?");
    mysqli_stmt_bind_param($stCnt, "i", $userId);
    mysqli_stmt_execute($stCnt);
    $rCnt = mysqli_stmt_get_result($stCnt);
    if ($rCnt && $rowCnt = mysqli_fetch_assoc($rCnt)) {
        $summaryCount1 = (int)$rowCnt['cnt'];
    }
} elseif ($role === 'admin') {
    $summaryLabel1 = 'Total Pesanan';
    $rCnt = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM bookings");
    if ($rCnt && $rowCnt = mysqli_fetch_assoc($rCnt)) {
        $summaryCount1 = (int)$rowCnt['cnt'];
    }
}

$avatarUrl = trim((string)($dbUser['avatar'] ?? ''));
$cleanAvatarFile = $avatarUrl !== '' ? __DIR__ . '/' . ltrim(explode('?', $avatarUrl)[0], '/') : '';
$hasAvatar = ($avatarUrl !== '' && is_file($cleanAvatarFile));
$avatarDisplayUrl = '';
if ($hasAvatar) {
    $sep = (strpos($avatarUrl, '?') !== false) ? '&' : '?';
    $avatarDisplayUrl = $avatarUrl . $sep . 'v=' . filemtime($cleanAvatarFile);
}
$initialLetter = strtoupper(mb_substr(trim((string)($dbUser['name'] ?? 'U')), 0, 1, 'UTF-8') ?: 'U');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Akun - KosFind USU</title>
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
        <div class="max-w-5xl mx-auto px-3 sm:px-6 h-16 flex items-center justify-between gap-3">
            <a href="index.php" class="flex items-center space-x-2 shrink-0">
                <div class="bg-teal-600 text-white p-2 rounded-xl font-bold text-base w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-house-chimney"></i>
                </div>
                <div>
                    <span class="text-base sm:text-lg font-black text-slate-900 tracking-tight">Kos<span class="text-teal-600">Find</span></span>
                    <span class="hidden md:block text-[10px] font-bold text-slate-400 uppercase tracking-wider -mt-1">Profil Akun</span>
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
                    <i id="navMenuChevron" class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"></i>
                </button>

                <div
                    id="navDropdownList"
                    class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-slate-200 py-2 z-50 text-xs"
                >
                    <div class="px-3.5 py-2.5 border-b border-slate-100 flex items-center gap-2.5 bg-slate-50/70">
                        <?php if ($hasAvatar): ?>
                            <img
                                src="<?= htmlspecialchars($avatarDisplayUrl) ?>"
                                alt="Foto <?= htmlspecialchars((string)$dbUser['name']) ?>"
                                class="w-8 h-8 rounded-full object-cover border border-teal-200 shrink-0"
                                onerror="this.outerHTML='<div class=\'w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center shrink-0\'><i class=\'fa-solid fa-circle-user text-base\'></i></div>';"
                            >
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center shrink-0">
                                <i class="fa-solid fa-circle-user text-base"></i>
                            </div>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <p class="font-extrabold text-slate-900 truncate"><?= htmlspecialchars((string)$dbUser['name']) ?></p>
                            <span class="inline-block text-[10px] uppercase font-extrabold px-1.5 py-0.5 rounded bg-white text-teal-700 border border-teal-200 mt-0.5">
                                <?= htmlspecialchars($role) ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-1.5 space-y-1">
                        <a href="index.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-700 hover:bg-slate-100 transition">
                            <i class="fa-solid fa-house w-4 text-center text-teal-600"></i>
                            <span>Katalog</span>
                        </a>
                        <?php if ($role === 'pemilik'): ?>
                            <a href="kos-saya.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-emerald-700 hover:bg-emerald-50 transition">
                                <i class="fa-solid fa-building-user w-4 text-center"></i>
                                <span>Kos Saya</span>
                            </a>
                            <a href="daftarkan-kos.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-teal-700 hover:bg-teal-50 transition">
                                <i class="fa-solid fa-plus-circle w-4 text-center"></i>
                                <span>Daftarkan Kos</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($role === 'pemilik' || $role === 'admin'): ?>
                            <a href="pesanan.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-indigo-700 hover:bg-indigo-50 transition">
                                <i class="fa-solid fa-receipt w-4 text-center"></i>
                                <span>Pesanan Masuk</span>
                            </a>
                        <?php else: ?>
                            <a href="pesanan-user.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-indigo-700 hover:bg-indigo-50 transition">
                                <i class="fa-solid fa-receipt w-4 text-center"></i>
                                <span>Pesanan User</span>
                            </a>
                        <?php endif; ?>

                        <?php if ($role === 'admin'): ?>
                            <a href="admin/index.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-amber-700 hover:bg-amber-50 transition">
                                <i class="fa-solid fa-sliders w-4 text-center"></i>
                                <span>CMS Admin</span>
                            </a>
                        <?php endif; ?>

                        <a href="profil.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-800 bg-slate-100 transition">
                            <i class="fa-solid fa-user-pen w-4 text-center text-teal-600"></i>
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
        <div class="max-w-5xl mx-auto space-y-6">
            <!-- HEADER PROFIL -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-6">
                <h1 class="text-lg sm:text-xl font-extrabold text-slate-900">Profil Akun</h1>
                <p class="text-xs text-slate-500 mt-1">Kelola foto profil, username, informasi kontak, dan keamanan akun KosFind USU.</p>
            </div>

            <?php if ($successMsg !== ''): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold px-4 py-3 rounded-xl">
                    <i class="fa-solid fa-circle-check mr-1"></i> <?= htmlspecialchars($successMsg) ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMsg !== ''): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-xl">
                    <i class="fa-solid fa-circle-exclamation mr-1"></i> <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- KARTU RINGKASAN PROFIL (4 KOLOM) -->
                <div class="lg:col-span-4 bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6 text-center space-y-4">
                    <div class="w-24 h-24 mx-auto rounded-full overflow-hidden border-2 border-teal-500 bg-teal-50 flex items-center justify-center shadow-sm">
                        <?php if ($hasAvatar): ?>
                            <img id="currentAvatarDisplay" src="<?= htmlspecialchars($avatarDisplayUrl) ?>" alt="Foto Profil" class="w-full h-full object-cover">
                        <?php else: ?>
                            <img id="currentAvatarDisplay" src="" alt="Foto Profil" class="hidden w-full h-full object-cover">
                            <span id="avatarInitialFallback" class="text-2xl font-black text-teal-700"><?= htmlspecialchars($initialLetter) ?></span>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h2 class="text-base font-extrabold text-slate-900"><?= htmlspecialchars((string)$dbUser['name']) ?></h2>
                        <p class="text-xs text-slate-500 mt-0.5">
                            <?= !empty($dbUser['username']) ? '@' . htmlspecialchars((string)$dbUser['username']) : 'Username belum diatur' ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars((string)$dbUser['email']) ?></p>
                        <span class="mt-2 inline-block text-[10px] uppercase font-extrabold px-2.5 py-1 rounded-full bg-teal-50 text-teal-700 border border-teal-200">
                            <?= htmlspecialchars($role) ?>
                        </span>
                    </div>

                    <div class="pt-3 border-t border-slate-100 grid grid-cols-1 gap-2 text-xs">
                        <div class="bg-slate-50 rounded-xl p-3 border border-slate-100">
                            <span class="text-[11px] text-slate-400 block font-semibold"><?= htmlspecialchars($summaryLabel1) ?></span>
                            <strong class="text-base font-black text-slate-800"><?= $summaryCount1 ?></strong>
                        </div>
                    </div>
                </div>

                <!-- FORM EDIT PROFIL & PASSWORD (8 KOLOM) -->
                <div class="lg:col-span-8 space-y-6">
                    <!-- FORM PROFIL & FOTO -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                        <h2 class="text-base font-bold text-slate-900 mb-4">Informasi Profil & Foto</h2>

                        <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                            <input type="hidden" name="form_action" value="update_profile">

                            <!-- PILIH FOTO DARI GALERI -->
                            <div>
                                <label class="font-bold text-slate-700 block mb-1.5">Foto Profil (Pilih dari Galeri)</label>
                                <div class="flex flex-col sm:flex-row sm:items-center gap-4 bg-slate-50 p-3.5 rounded-2xl border border-slate-200">
                                    <img id="avatarPreviewBox"
                                         src="<?= $hasAvatar ? htmlspecialchars($avatarDisplayUrl) : '' ?>"
                                         alt="Preview Avatar"
                                         class="<?= $hasAvatar ? '' : 'hidden' ?> w-16 h-16 rounded-full object-cover border border-slate-300 shrink-0">
                                    <div class="flex-1 min-w-0">
                                        <label for="avatarFileInput" class="inline-flex items-center gap-1.5 bg-white hover:bg-teal-50 text-teal-700 border border-teal-200 font-bold px-3.5 py-2 rounded-xl cursor-pointer transition">
                                            <i class="fa-solid fa-images"></i> Pilih Foto dari Galeri
                                        </label>
                                        <input id="avatarFileInput" type="file" name="avatar" accept="image/jpeg,image/png,image/webp,image/*" class="hidden" onchange="previewAvatarFile(this)">
                                        <p id="avatarSelectedName" class="text-[11px] text-slate-500 mt-1.5">JPG, PNG, atau WEBP • Maksimal 2 MB</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Nama Lengkap *</label>
                                    <input type="text" name="name" required value="<?= htmlspecialchars((string)($dbUser['name'] ?? '')) ?>"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Username</label>
                                    <input type="text" name="username" value="<?= htmlspecialchars((string)($dbUser['username'] ?? '')) ?>"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                    <span class="text-[11px] text-slate-400 mt-1 block">Huruf, angka, spasi, titik, underscore, atau tanda hubung (3–30 karakter).</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Email</label>
                                    <input type="email" disabled value="<?= htmlspecialchars((string)($dbUser['email'] ?? '')) ?>"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-100 text-slate-500 cursor-not-allowed">
                                </div>
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Nomor HP / WhatsApp</label>
                                    <input type="text" name="phone" value="<?= htmlspecialchars((string)($dbUser['phone'] ?? '')) ?>"
                                           placeholder="Contoh: 081260007890"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                </div>
                            </div>

                            <div class="pt-1 flex justify-end">
                                <button type="submit" class="bg-teal-600 hover:bg-teal-700 text-white font-bold px-5 py-2.5 rounded-xl shadow-sm transition">
                                    <i class="fa-solid fa-floppy-disk mr-1"></i> Simpan Profil
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- FORM GANTI KATA SANDI -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
                        <h2 class="text-base font-bold text-slate-900 mb-4">Ubah Kata Sandi</h2>

                        <form method="POST" class="space-y-4 text-xs">
                            <input type="hidden" name="form_action" value="change_password">

                            <div>
                                <label class="font-bold text-slate-700 block mb-1">Kata Sandi Saat Ini *</label>
                                <input type="password" name="current_password" required
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Kata Sandi Baru *</label>
                                    <input type="password" name="new_password" required minlength="6"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Konfirmasi Kata Sandi Baru *</label>
                                    <input type="password" name="confirm_password" required minlength="6"
                                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                </div>
                            </div>

                            <div class="pt-1 flex justify-end">
                                <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-bold px-5 py-2.5 rounded-xl shadow-sm transition">
                                    <i class="fa-solid fa-key mr-1"></i> Perbarui Kata Sandi
                                </button>
                            </div>
                        </form>
                    </div>
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

        function previewAvatarFile(input) {
            const nameEl = document.getElementById('avatarSelectedName');
            const previewBox = document.getElementById('avatarPreviewBox');
            const cardImg = document.getElementById('currentAvatarDisplay');
            const fallbackEl = document.getElementById('avatarInitialFallback');

            if (input.files && input.files[0]) {
                const file = input.files[0];
                if (nameEl) nameEl.innerText = 'Dipilih: ' + file.name;

                const reader = new FileReader();
                reader.onload = function (e) {
                    if (previewBox) {
                        previewBox.src = e.target.result;
                        previewBox.classList.remove('hidden');
                    }
                    if (cardImg) {
                        cardImg.src = e.target.result;
                        cardImg.classList.remove('hidden');
                    }
                    if (fallbackEl) {
                        fallbackEl.classList.add('hidden');
                    }
                };
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
</html>