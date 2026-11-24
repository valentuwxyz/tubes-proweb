<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

$user = currentUser();
$menuBadgeCount = 0;
if ($user && !empty($user['id'])) {
    $roleLower = strtolower((string)($user['role'] ?? 'user'));
    $menuBadgeCount = getUnreadBadgeCount($conn, (int)$user['id'], $roleLower);

    if ($roleLower === 'admin' || $roleLower === 'pemilik') {
        $tblBookings = mysqli_query($conn, "SHOW TABLES LIKE 'bookings'");
        if ($tblBookings && mysqli_num_rows($tblBookings) > 0) {
            if ($roleLower === 'admin') {
                $resPending = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM bookings WHERE LOWER(status) = 'pending'");
            } else {
                $uid = (int)$user['id'];
                $resPending = mysqli_query(
                    $conn,
                    "SELECT COUNT(*) AS cnt FROM bookings b
                     JOIN kos k ON b.kos_id = k.id
                     WHERE LOWER(b.status) = 'pending' AND k.owner_id = {$uid}"
                );
            }
            if ($resPending && ($pRow = mysqli_fetch_assoc($resPending))) {
                $pendingCnt = (int)($pRow['cnt'] ?? 0);
                if ($pendingCnt > $menuBadgeCount) {
                    $menuBadgeCount = $pendingCnt;
                }
            }
        }
    }
}

// Deteksi nama kolom pada tabel kos & ketersediaan tabel users
$roomsCol = null;
$hasRulesCol = false;
$hasGalleryCol = false;
$hasCampusCol = false;
$hasPdamCol = false;
$hasSecurityCol = false;
$hasOwnerIdCol = false;
$kosCols = [];

$colRes = mysqli_query($conn, "SHOW COLUMNS FROM kos");
if ($colRes) {
    while ($c = mysqli_fetch_assoc($colRes)) {
        $kosCols[] = $c['Field'];
    }
    if (in_array('available_rooms', $kosCols, true)) {
        $roomsCol = 'available_rooms';
    } elseif (in_array('sisa_kamar', $kosCols, true)) {
        $roomsCol = 'sisa_kamar';
    }
    $hasRulesCol = in_array('rules', $kosCols, true);
    $hasGalleryCol = in_array('gallery_images', $kosCols, true);
    $hasCampusCol = in_array('campus_name', $kosCols, true);
    $hasPdamCol = in_array('pdam', $kosCols, true);
    $hasSecurityCol = in_array('security', $kosCols, true);
    $hasOwnerIdCol = in_array('owner_id', $kosCols, true);
}

$hasUsersTable = false;
$userCols = [];
$tblUsersRes = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
if ($tblUsersRes && mysqli_num_rows($tblUsersRes) > 0) {
    $hasUsersTable = true;
    $colUserRes = mysqli_query($conn, "SHOW COLUMNS FROM users");
    if ($colUserRes) {
        while ($uc = mysqli_fetch_assoc($colUserRes)) {
            $userCols[] = $uc['Field'];
        }
    }
}

// Ambil data kos dari database kosfind (sertakan data pemilik jika tersedia)
if ($hasOwnerIdCol && $hasUsersTable) {
    $query = "SELECT k.*, u.name AS owner_user_name, u.role AS owner_user_role" .
             (in_array('avatar', $userCols, true) ? ", u.avatar AS owner_user_avatar" : "") .
             (in_array('phone', $userCols, true) ? ", u.phone AS owner_user_phone" : "") .
             (in_array('whatsapp', $userCols, true) ? ", u.whatsapp AS owner_user_whatsapp" : "") .
             " FROM kos k LEFT JOIN users u ON k.owner_id = u.id ORDER BY k.id ASC";
} else {
    $query = "SELECT * FROM kos ORDER BY id ASC";
}
$result = mysqli_query($conn, $query);

// Ambil semua ulasan dan kelompokkan per kos_id
$reviewsMap = [];
$revQuery = "SELECT id, kos_id, reviewer_name, rating, comment, created_at FROM reviews ORDER BY id DESC";
$revResult = mysqli_query($conn, $revQuery);
if ($revResult) {
    while ($revRow = mysqli_fetch_assoc($revResult)) {
        $kId = (int)$revRow['kos_id'];
        $reviewsMap[$kId][] = [
            'id' => (int)$revRow['id'],
            'reviewerName' => $revRow['reviewer_name'],
            'rating' => (int)$revRow['rating'],
            'comment' => $revRow['comment'],
            'createdAt' => date('d M Y', strtotime($revRow['created_at']))
        ];
    }
}

$kosList = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $facilitiesArray = array_filter(array_map('trim', explode(',', $row['facilities'] ?? '')));

        $rawAddress = trim((string)($row['address'] ?? $row['alamat'] ?? ''));
        $cleanAddress = ($rawAddress === '0') ? '' : $rawAddress;
        $availableRooms = ($roomsCol !== null) ? max(0, (int)($row[$roomsCol] ?? 0)) : 1;
        $rulesText = $hasRulesCol ? (string)($row['rules'] ?? '') : '';
        $campusName = $hasCampusCol ? trim((string)($row['campus_name'] ?? '')) : '';
        if ($campusName === '') {
            $campusName = 'USU';
        }
        $pdamText = $hasPdamCol ? trim((string)($row['pdam'] ?? '')) : '';
        if ($pdamText === '') {
            $pdamText = 'Sudah Termasuk Biaya Kos';
        }
        $securityText = $hasSecurityCol ? trim((string)($row['security'] ?? '')) : '';
        if ($securityText === '') {
            $securityText = 'CCTV 24 Jam';
        }

        $ownerId = (int)($row['owner_id'] ?? 0);
        $ownerName = trim((string)($row['owner_user_name'] ?? $row['owner_name'] ?? ''));
        if ($ownerName === '') {
            $ownerName = 'Pemilik Kos Terdaftar';
        }
        $ownerRole = trim((string)($row['owner_user_role'] ?? 'pemilik'));
        $ownerAvatar = trim((string)($row['owner_user_avatar'] ?? $row['owner_avatar'] ?? ''));
        $ownerPhone = trim((string)($row['owner_user_phone'] ?? $row['owner_user_whatsapp'] ?? $row['owner_whatsapp'] ?? ''));
        $ownerWhatsapp = trim((string)($row['owner_whatsapp'] ?? $row['owner_user_whatsapp'] ?? $row['owner_user_phone'] ?? '6281260007890'));
        if ($ownerWhatsapp === '') {
            $ownerWhatsapp = '6281260007890';
        }

        $galleryList = [];
        if ($hasGalleryCol && !empty($row['gallery_images'])) {
            $decodedGallery = json_decode((string)$row['gallery_images'], true);
            if (is_array($decodedGallery)) {
                foreach ($decodedGallery as $gPath) {
                    $gClean = trim((string)$gPath);
                    if ($gClean !== '' && !in_array($gClean, $galleryList, true)) {
                        $galleryList[] = $gClean;
                    }
                }
            }
        }
        $mainImgClean = trim((string)($row['main_image'] ?? ''));
        if ($mainImgClean !== '' && !in_array($mainImgClean, $galleryList, true)) {
            array_unshift($galleryList, $mainImgClean);
        }
        if (empty($galleryList)) {
            $galleryList[] = 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80';
        }

        $kosList[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'campusName' => $campusName,
            'type' => $row['type'],
            'price' => (int)$row['price'],
            'priceFormatted' => 'Rp ' . number_format((int)$row['price'], 0, ',', '.'),
            'availableRooms' => $availableRooms,
            'distance' => $row['distance'],
            'address' => $cleanAddress,
            'rating' => (float)$row['rating'],
            'size' => $row['size'],
            'electricity' => $row['electricity'],
            'pdam' => $pdamText,
            'security' => $securityText,
            'description' => $row['description'],
            'rules' => $rulesText,
            'images' => $galleryList,
            'facilities' => array_values($facilitiesArray),
            'mapQuery' => ($row['map_query'] ?: $cleanAddress) ?: ('Kampus ' . $campusName),
            'ownerId' => $ownerId,
            'ownerName' => $ownerName,
            'ownerRole' => $ownerRole,
            'ownerAvatar' => $ownerAvatar,
            'ownerPhone' => $ownerPhone,
            'ownerWhatsapp' => $ownerWhatsapp,
            'reviews' => $reviewsMap[(int)$row['id']] ?? []
        ];
    }
}

$campusOptions = [];
foreach ($kosList as $kItem) {
    $cName = trim((string)($kItem['campusName'] ?? ''));
    if ($cName !== '' && !in_array($cName, $campusOptions, true)) {
        $campusOptions[] = $cName;
    }
}
natcasesort($campusOptions);
$campusOptions = array_values($campusOptions);

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
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KosFind - Cari & Sewa Kos Nyaman Tanpa Ribet</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen flex flex-col">

    <!-- NAVBAR RAPI DENGAN TOMBOL GARIS TIGA (DROPDOWN) -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-40 w-full shadow-xs">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-3">
            <a href="index.php" class="flex items-center space-x-2 shrink-0">
                <div class="bg-teal-600 text-white p-2 rounded-xl font-bold text-base w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center shadow-sm">
                    <i class="fa-solid fa-house-chimney"></i>
                </div>
                <div>
                    <span class="text-base sm:text-lg font-black text-slate-900 tracking-tight">Kos<span class="text-teal-600">Find</span></span>
                    <span class="hidden md:block text-[10px] font-bold text-slate-400 uppercase tracking-wider -mt-1">Cari Kos Sekitar Kampus</span>
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
                    <?php if (!$user): ?>
                        <div class="px-3.5 py-2 border-b border-slate-100">
                            <p class="text-[11px] font-semibold text-slate-400">Belum masuk akun</p>
                        </div>
                        <div class="p-1.5 space-y-1">
                            <a href="login.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-700 hover:bg-teal-50 hover:text-teal-700 transition">
                                <i class="fa-solid fa-right-to-bracket w-4 text-center text-teal-600"></i>
                                <span>Masuk</span>
                            </a>
                            <a href="register.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-white bg-teal-600 hover:bg-teal-700 transition">
                                <i class="fa-solid fa-user-plus w-4 text-center"></i>
                                <span>Daftar Akun</span>
                            </a>
                        </div>
                    <?php else: ?>
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
                            <?php if ($user['role'] === 'pemilik'): ?>
                                <a href="kos-saya.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-emerald-700 hover:bg-emerald-50 transition">
                                    <i class="fa-solid fa-building-user w-4 text-center"></i>
                                    <span>Kos Saya</span>
                                </a>
                                <a href="daftarkan-kos.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-teal-700 hover:bg-teal-50 transition">
                                    <i class="fa-solid fa-plus-circle w-4 text-center"></i>
                                    <span>Daftarkan Kos</span>
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
                            <?php else: ?>
                                <a href="pesanan-user.php" class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl font-bold text-indigo-700 hover:bg-indigo-50 transition">
                                    <span class="flex items-center gap-2.5">
                                        <i class="fa-solid fa-receipt w-4 text-center"></i>
                                        <span>Pesanan User</span>
                                    </span>
                                    <?php if ($menuBadgeCount > 0): ?>
                                        <span class="bg-emerald-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full leading-none">
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- CONTAINER TAMPILAN KATALOG (PAGE 1) -->
    <div id="catalogPage" class="flex-1 w-full">
        <section class="bg-gradient-to-br from-teal-950 via-teal-900 to-slate-900 text-white py-10 sm:py-14 px-4 relative overflow-hidden">
            <div class="absolute inset-0 opacity-10 bg-[radial-gradient(#38bdf8_1px,transparent_1px)] [background-size:16px_16px]"></div>
            <div class="max-w-4xl mx-auto text-center space-y-3 sm:space-y-4 relative z-10">
                <span class="bg-teal-500/20 text-teal-300 text-[11px] sm:text-xs font-semibold px-3 py-1 rounded-full border border-teal-500/30 inline-block">
                    <i class="fa-solid fa-graduation-cap mr-1"></i> Terhubung Langsung dengan Data Kampus Pilihan Pemilik
                </span>
                <h1 class="text-2xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight leading-tight">
                    Cari & Sewa Kos Sekitar Kampusmu<br><span class="text-teal-400">Lengkap dengan Peta & Detail Transparan</span>
                </h1>
                <p class="text-slate-300 text-xs sm:text-base max-w-2xl mx-auto leading-relaxed">
                    Cari kos berdasarkan nama kampus seperti USU, Unimed, Polmed, atau kampus lain yang sudah didaftarkan pemilik.
                </p>
            </div>
        </section>

        <main class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-6 sm:py-8">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 sm:p-5 mb-6 sm:mb-8 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
                    <div class="md:col-span-6 relative">
                        <i class="fa-solid fa-magnifying-glass text-slate-400 text-xs absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                        <input
                            type="text"
                            id="campusSearchInput"
                            oninput="updateCampusSearch(this.value)"
                            placeholder="Cari nama kampus (USU, Unimed, Polmed...), nama kos, atau pemilik..."
                            class="w-full pl-9 pr-4 py-2 text-xs rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none"
                        >
                    </div>

                    <div class="md:col-span-4">
                        <select
                            id="campusSelectFilter"
                            onchange="setCampusDropdownFilter(this.value)"
                            class="w-full px-3.5 py-2 text-xs font-bold text-slate-700 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none"
                        >
                            <option value="All">Semua Kampus Terdaftar</option>
                            <?php foreach ($campusOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>"><?= htmlspecialchars($cOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md:col-span-2 flex justify-end">
                        <button
                            type="button"
                            onclick="resetAllFilters()"
                            class="w-full px-3 py-2 text-xs font-bold text-slate-600 hover:text-teal-700 bg-slate-100 hover:bg-teal-50 border border-slate-200 rounded-xl transition"
                        >
                            <i class="fa-solid fa-rotate-left mr-1"></i> Reset
                        </button>
                    </div>
                </div>

                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 sm:gap-6 pt-3 border-t border-slate-100">
                    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider mr-1 sm:mr-2">
                            <i class="fa-solid fa-filter text-teal-600 mr-1"></i> Gender:
                        </span>
                        <button onclick="setFilterType('All')" id="btnTypeAll" class="filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-teal-600 text-white shadow-sm transition">Semua</button>
                        <button onclick="setFilterType('Putri')" id="btnTypePutri" class="filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Putri</button>
                        <button onclick="setFilterType('Putra')" id="btnTypePutra" class="filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Putra</button>
                        <button onclick="setFilterType('Campur')" id="btnTypeCampur" class="filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition">Campur</button>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4 bg-slate-50 p-3 rounded-xl border border-slate-100 w-full lg:w-auto">
                        <span class="text-xs font-semibold text-slate-600 whitespace-nowrap">
                            Maks. Biaya: <strong id="priceLabel" class="text-teal-600 font-bold">Rp 8.000.000</strong>
                        </span>
                        <input type="range" id="priceRange" min="500000" max="8000000" step="100000" value="8000000" class="accent-teal-600 cursor-pointer w-full sm:w-48" oninput="updatePriceFilter(this.value)">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">
                <div class="lg:col-span-7 space-y-4 min-w-0">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-1">
                        <h2 class="text-sm sm:text-base font-bold text-slate-800" id="resultCount">Menampilkan <?= count($kosList) ?> tempat kos</h2>
                        <span class="text-[11px] sm:text-xs text-teal-700 font-semibold bg-teal-50 px-3 py-1 rounded-full border border-teal-100">
                            <i class="fa-solid fa-mouse-pointer mr-1"></i> Klik untuk lihat detail & peta
                        </span>
                    </div>

                    <div id="listingContainer" class="space-y-4"></div>
                </div>

                <div class="lg:col-span-5 lg:sticky lg:top-20 min-w-0 w-full">
                    <div class="bg-white p-3.5 sm:p-4 rounded-2xl shadow-sm border border-slate-200">
                        <div class="flex items-center justify-between gap-2 mb-3 px-1">
                            <h3 class="font-bold text-slate-800 text-xs sm:text-sm flex items-center gap-2 shrink-0">
                                <i class="fa-solid fa-map-location-dot text-teal-600"></i> Peta Lokasi Terpilih
                            </h3>
                            <span id="activeMapTitle" class="text-[11px] sm:text-xs font-semibold text-teal-700 bg-teal-50 px-2.5 py-1 rounded-lg border border-teal-100 truncate max-w-[160px] sm:max-w-[220px]">
                                Kampus Terpilih
                            </span>
                        </div>
                        <div class="map-container h-64 sm:h-80 lg:h-[420px] overflow-hidden rounded-xl bg-slate-100 relative">
                            <iframe id="mapFrame"
                                src="https://www.google.com/maps?q=Universitas+Sumatera+Utara&output=embed"
                                allowfullscreen=""
                                loading="lazy">
                            </iframe>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- CONTAINER TAMPILAN DETAIL KOS (PAGE 2) -->
    <div id="detailPage" class="hidden flex-1 bg-slate-50 py-6 sm:py-8 w-full">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                <button onclick="showCatalogPage()" class="inline-flex items-center gap-2 text-xs sm:text-sm font-bold text-teal-600 hover:text-teal-700 bg-white px-4 py-2 rounded-xl shadow-sm border border-slate-200 transition">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Kos
                </button>
                <div class="text-[11px] sm:text-xs font-medium text-slate-400 truncate">
                    <span>Beranda</span> <i class="fa-solid fa-chevron-right text-[10px] mx-1"></i>
                    <span id="detailBreadcrumbCampus">Kampus</span> <i class="fa-solid fa-chevron-right text-[10px] mx-1"></i>
                    <span id="detailBreadcrumbTitle" class="text-slate-700 font-semibold">Detail Kos</span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">
                <div class="lg:col-span-8 space-y-6 min-w-0">
                    <div class="bg-white p-3.5 sm:p-4 rounded-2xl shadow-sm border border-slate-200 space-y-3">
                        <div class="relative h-60 sm:h-88 md:h-96 rounded-xl overflow-hidden bg-slate-100">
                            <img id="detailMainImage" src="" alt="Foto Utama Kos" class="w-full h-full object-cover">
                            <span id="detailBadgeType" class="absolute top-3 left-3 sm:top-4 sm:left-4 bg-teal-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm">
                                Putri
                            </span>
                        </div>
                        <div id="detailGalleryThumbs" class="grid grid-cols-4 sm:grid-cols-6 gap-2"></div>
                    </div>

                    <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span id="detailCampusBadge" class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 border border-indigo-200 text-[11px] font-bold px-2.5 py-0.5 rounded-full">
                                        <i class="fa-solid fa-graduation-cap"></i> <span id="detailCampusText">USU</span>
                                    </span>
                                </div>
                                <h1 id="detailTitle" class="text-xl sm:text-2xl font-extrabold text-slate-900">Nama Kos</h1>
                                <p id="detailAddress" class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                    <i class="fa-solid fa-location-dot text-teal-600"></i>
                                    <span id="detailAddressText">Alamat Lengkap Kos</span>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span id="detailAvailableRoomsBadge" class="inline-flex items-center gap-1 bg-teal-50 text-teal-700 px-3 py-1.5 rounded-xl border border-teal-200 font-bold text-xs sm:text-sm">
                                    <i class="fa-solid fa-door-open"></i> <span id="detailAvailableRoomsText">Sisa 1 Kamar</span>
                                </span>
                                <div class="flex items-center gap-1 bg-amber-50 text-amber-600 px-3 py-1.5 rounded-xl border border-amber-200 font-bold text-xs sm:text-sm">
                                    <i class="fa-solid fa-star text-amber-400"></i> <span id="detailRating">4.9</span> (Terverifikasi)
                                </div>
                            </div>
                        </div>

                        <div class="bg-slate-50 hover:bg-teal-50/40 p-3.5 sm:p-4 rounded-2xl border border-slate-200 hover:border-teal-300 flex flex-wrap items-center justify-between gap-3 transition">
                            <a id="detailOwnerProfileLink" href="index.php" class="flex items-center gap-3 min-w-0 flex-1 group">
                                <div id="detailOwnerAvatarWrap" class="w-11 h-11 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center shrink-0 overflow-hidden border border-teal-200">
                                    <i class="fa-solid fa-user-tie text-lg"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400">Profil Pemilik Kos</span>
                                        <span id="detailOwnerRoleBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Pemilik Kos
                                        </span>
                                    </div>
                                    <p id="detailOwnerName" class="text-sm font-extrabold text-slate-900 group-hover:text-teal-700 underline decoration-teal-500/40 underline-offset-2 truncate mt-0.5">Nama Pemilik</p>
                                    <p id="detailOwnerContact" class="text-[11px] text-slate-500 truncate mt-0.5">
                                        <i class="fa-solid fa-phone text-teal-600 mr-1"></i><span id="detailOwnerContactText">Kontak tersedia via WA</span>
                                    </p>
                                </div>
                            </a>
                            <div class="flex items-center gap-2 shrink-0">
                                <a id="detailOwnerProfileBtn" href="index.php" class="inline-flex items-center gap-1 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 px-3 py-2 rounded-xl text-xs font-bold transition">
                                    <span>Profil</span>
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                                </a>
                                <button type="button" onclick="bookViaWhatsApp()" class="inline-flex items-center gap-1.5 bg-white hover:bg-emerald-50 text-emerald-700 border border-emerald-200 px-3 py-2 rounded-xl text-xs font-bold transition cursor-pointer">
                                    <i class="fa-brands fa-whatsapp text-sm"></i> Chat Pemilik
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 pt-2">
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center">
                                <i class="fa-solid fa-ruler-combined text-teal-600 text-lg mb-1 block"></i>
                                <span class="text-[10px] text-slate-400 block font-bold">Ukuran Kamar</span>
                                <span id="detailSpecSize" class="text-xs font-bold text-slate-700">3 x 4 Meter</span>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center">
                                <i class="fa-solid fa-bolt text-teal-600 text-lg mb-1 block"></i>
                                <span class="text-[10px] text-slate-400 block font-bold">Listrik</span>
                                <span id="detailSpecElectricity" class="text-xs font-bold text-slate-700">Token Sendiri</span>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center">
                                <i class="fa-solid fa-droplet text-teal-600 text-lg mb-1 block"></i>
                                <span class="text-[10px] text-slate-400 block font-bold">PDAM / Air</span>
                                <span id="detailSpecPdam" class="text-xs font-bold text-slate-700">Sudah Termasuk Biaya Kos</span>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center">
                                <i class="fa-solid fa-shield-halved text-teal-600 text-lg mb-1 block"></i>
                                <span class="text-[10px] text-slate-400 block font-bold">Keamanan</span>
                                <span id="detailSpecSecurity" class="text-xs font-bold text-slate-700">CCTV 24 Jam</span>
                            </div>
                            <div class="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center">
                                <i class="fa-solid fa-person-walking text-teal-600 text-lg mb-1 block"></i>
                                <span class="text-[10px] text-slate-400 block font-bold">Jarak Kampus</span>
                                <span id="detailSpecDistance" class="text-xs font-bold text-slate-700">300m dari Kampus</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-slate-200 space-y-6">
                        <div>
                            <h3 class="text-base font-bold text-slate-900 mb-2">Deskripsi Hunian</h3>
                            <p id="detailDescription" class="text-xs sm:text-sm text-slate-600 leading-relaxed"></p>
                        </div>

                        <hr class="border-slate-100">

                        <div>
                            <h3 class="text-base font-bold text-slate-900 mb-3">Fasilitas Kamar & Umum</h3>
                            <div id="detailFacilitiesGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3"></div>
                        </div>

                        <hr class="border-slate-100">

                        <div>
                            <h3 class="text-base font-bold text-slate-900 mb-3">Peraturan Kos</h3>
                            <ul id="detailRulesList" class="space-y-2 text-xs text-slate-600">
                                <li class="flex items-center gap-2"><i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i> Tamu lawan jenis dilarang masuk ke dalam kamar.</li>
                                <li class="flex items-center gap-2"><i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i> Jam malam pagar utama dikunci pukul 23:00 WIB.</li>
                                <li class="flex items-center gap-2"><i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i> Menjaga kebersihan area umum & dapur bersama.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
                        <h3 class="text-base font-bold text-slate-900">Lokasi & Area Sekitar</h3>
                        <div class="h-60 sm:h-72 rounded-xl overflow-hidden bg-slate-100">
                            <iframe id="detailMapFrame" class="w-full h-full border-0" allowfullscreen="" loading="lazy"></iframe>
                        </div>
                    </div>

                    <div id="ulasan-kos" class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-slate-200 space-y-6">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
                            <div>
                                <h3 class="text-base font-bold text-slate-900">Ulasan & Rating Kos</h3>
                                <p class="text-xs text-slate-500 mt-0.5">Pengalaman mahasiswa / penyewa terkait kenyamanan, fasilitas, dan lingkungan.</p>
                            </div>
                            <span id="detailReviewCount" class="text-xs font-bold text-teal-700 bg-teal-50 px-3 py-1 rounded-full border border-teal-100">
                                0 Ulasan
                            </span>
                        </div>

                        <form action="tambah-ulasan.php" method="POST" class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3 text-xs">
                            <input type="hidden" name="kos_id" id="reviewKosId" value="">

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Nama Pengulas *</label>
                                    <input type="text" name="reviewer_name" required
                                        value="<?= htmlspecialchars($user['name'] ?? '') ?>"
                                        placeholder="Nama kamu / mahasiswa"
                                        class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                </div>
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Rating (1 - 5 Bintang) *</label>
                                    <select name="rating" required class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                        <option value="5">5 Bintang - Sangat Memuaskan</option>
                                        <option value="4">4 Bintang - Nyaman & Bagus</option>
                                        <option value="3">3 Bintang - Cukup Oke</option>
                                        <option value="2">2 Bintang - Kurang Nyaman</option>
                                        <option value="1">1 Bintang - Perlu Banyak Perbaikan</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="font-bold text-slate-700 block mb-1">Isi Ulasan *</label>
                                <textarea name="comment" rows="3" required
                                    placeholder="Ceritakan kondisi kamar, Wi-Fi, air/listrik, keamanan, atau jarak ke kampus..."
                                    class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none"></textarea>
                            </div>

                            <button type="submit" class="w-full sm:w-auto bg-teal-600 hover:bg-teal-700 text-white font-bold py-2.5 px-5 rounded-xl shadow-sm transition">
                                <i class="fa-solid fa-paper-plane mr-1"></i> Kirim Ulasan & Rating
                            </button>
                        </form>

                        <div id="detailReviewsList" class="space-y-3"></div>
                    </div>
                </div>

                <div class="lg:col-span-4 lg:sticky lg:top-20 space-y-6 min-w-0 w-full">
                    <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-md border border-teal-100 space-y-5">
                        <div class="border-b border-slate-100 pb-4">
                            <span class="text-xs text-slate-400 font-semibold block">Harga Sewa Kamar</span>
                            <div class="flex items-baseline gap-1 mt-1">
                                <span id="detailPrice" class="text-2xl font-black text-teal-600">Rp 0</span>
                                <span class="text-xs text-slate-500 font-medium">/ bulan</span>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <label class="text-xs font-bold text-slate-700 block">Pilih Durasi Sewa:</label>
                            <select id="rentalDuration" onchange="calculateTotalPrice()" class="w-full text-xs font-semibold p-3 rounded-xl border border-slate-200 bg-slate-50 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                <option value="1">1 Bulan (Normal)</option>
                                <option value="3">3 Bulan (Hemat 2%)</option>
                                <option value="6">6 Bulan (Hemat 5%)</option>
                                <option value="12">12 Bulan / 1 Tahun (Diskon 10%)</option>
                            </select>

                            <div class="bg-teal-50 p-3 rounded-xl border border-teal-100 flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold text-teal-900">Estimasi Total:</span>
                                <span id="calculatedTotal" class="text-sm sm:text-base font-extrabold text-teal-700">Rp 0</span>
                            </div>
                        </div>

                        <div class="space-y-2 pt-2">
                            <a id="btnPesanLangsung" href="ajukan-sewa.php?kos_id=1&duration=1" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 px-4 rounded-xl shadow-md transition flex items-center justify-center gap-2 text-xs sm:text-sm">
                                <i class="fa-solid fa-calendar-check"></i> Pesan Kos Ini (Catat Pesanan)
                            </a>
                            <button onclick="bookViaWhatsApp()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-4 rounded-xl shadow-md transition flex items-center justify-center gap-2 text-xs sm:text-sm">
                                <i class="fa-brands fa-whatsapp text-lg"></i> Hubungi Pemilik & Sewa
                            </button>
                        </div>

                        <div class="text-[11px] text-slate-400 text-center leading-relaxed">
                            <i class="fa-solid fa-shield-cat text-teal-600 mr-1"></i> Terverifikasi Bebas Penipuan. Pembayaran dilakukan saat masuk kos.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <section id="cara-kerja" class="bg-white py-12 sm:py-14 border-t border-slate-200">
        <div class="max-w-7xl mx-auto px-4 text-center">
            <h2 class="text-xl sm:text-2xl font-bold text-slate-900 mb-6 sm:mb-8">Cara Mudah Sewa Kos di KosFind</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5 sm:gap-6">
                <div class="p-5 sm:p-6 rounded-2xl bg-slate-50 border border-slate-100">
                    <div class="w-12 h-12 bg-teal-100 text-teal-600 rounded-xl flex items-center justify-center mx-auto mb-4 text-xl font-bold">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <h3 class="font-bold text-base mb-2">1. Cari Kampus & Filter Kamar</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">Cari nama kampus tujuan (USU, Unimed, Polmed, dll.), tipe gender, dan batas tarif bulanan.</p>
                </div>
                <div class="p-5 sm:p-6 rounded-2xl bg-slate-50 border border-slate-100">
                    <div class="w-12 h-12 bg-teal-100 text-teal-600 rounded-xl flex items-center justify-center mx-auto mb-4 text-xl font-bold">
                        <i class="fa-solid fa-eye"></i>
                    </div>
                    <h3 class="font-bold text-base mb-2">2. Cek Detail & Fasilitas</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">Lihat foto galeri kamar, listrik, PDAM, keamanan, fasilitas umum, dan jarak ke kampus.</p>
                </div>
                <div class="p-5 sm:p-6 rounded-2xl bg-slate-50 border border-slate-100">
                    <div class="w-12 h-12 bg-teal-100 text-teal-600 rounded-xl flex items-center justify-center mx-auto mb-4 text-xl font-bold">
                        <i class="fa-brands fa-whatsapp"></i>
                    </div>
                    <h3 class="font-bold text-base mb-2">3. Chat Pemilik via WA</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">Klik tombol sewa untuk terhubung langsung via WhatsApp dengan format pesan otomatis.</p>
                </div>
            </div>
        </div>
    </section>

    <footer class="bg-slate-900 text-slate-400 py-10 text-xs mt-auto">
        <div class="max-w-7xl mx-auto px-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8">
            <div>
                <div class="flex items-center space-x-2 mb-3">
                    <div class="bg-teal-600 text-white p-1.5 rounded-lg font-bold text-base w-7 h-7 flex items-center justify-center">
                        <i class="fa-solid fa-house-chimney"></i>
                    </div>
                    <span class="text-base font-bold text-white">KosFind</span>
                </div>
                <p class="text-slate-400 leading-relaxed">Platform pencarian tempat kos mahasiswa yang terhubung langsung dengan data pendaftaran pemilik.</p>
            </div>
            <div>
                <h4 class="text-white font-bold mb-3">Kampus Populer</h4>
                <ul class="space-y-2">
                    <li><a href="#" onclick="event.preventDefault(); quickFilterCampus('USU');" class="hover:text-white transition">Kos Sekitar Kampus USU</a></li>
                    <li><a href="#" onclick="event.preventDefault(); quickFilterCampus('Unimed');" class="hover:text-white transition">Kos Sekitar Kampus Unimed</a></li>
                    <li><a href="#" onclick="event.preventDefault(); quickFilterCampus('Polmed');" class="hover:text-white transition">Kos Sekitar Kampus Polmed</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-white font-bold mb-3">Bantuan & Syarat</h4>
                <ul class="space-y-2">
                    <li><a href="#" class="hover:text-white transition">Panduan Penyewa</a></li>
                    <li><a href="admin/index.php" class="hover:text-white transition">Kelola Data Kos (CMS)</a></li>
                    <li><a href="#" class="hover:text-white transition">Kebijakan Privasi</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-white font-bold mb-3">Kontak Info</h4>
                <p class="mb-2"><i class="fa-solid fa-envelope mr-2"></i> info@kosfind.ac.id</p>
                <p><i class="fa-solid fa-phone mr-2"></i> +62 812-6000-7890</p>
            </div>
        </div>
        <div class="max-w-7xl mx-auto px-4 border-t border-slate-800 mt-8 pt-6 text-center text-slate-500">
            &copy; 2026 KosFind Platform. All rights reserved.
        </div>
    </footer>

    <script>
        const kosData = <?= json_encode($kosList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        let currentTypeFilter = 'All';
        let currentCampusDropdown = 'All';
        let currentCampusSearch = '';
        let maxPriceFilter = 8000000;
        let activeCardId = kosData.length > 0 ? kosData[0].id : null;
        let selectedKosForDetail = null;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

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

        document.addEventListener('DOMContentLoaded', () => {
            const priceRangeEl = document.getElementById('priceRange');
            if (priceRangeEl) {
                maxPriceFilter = parseInt(priceRangeEl.value, 10) || 8000000;
                document.getElementById('priceLabel').innerText = "Rp " + maxPriceFilter.toLocaleString('id-ID');
            }

            renderCatalogListing();
            if (kosData.length > 0) {
                updateCatalogMap(kosData[0].name, kosData[0].mapQuery);
            }

            const urlParams = new URLSearchParams(window.location.search);
            const openKosId = parseInt(urlParams.get('open_kos') || '0', 10);
            if (openKosId > 0 && kosData.some(k => k.id === openKosId)) {
                openDetailPage(openKosId);
            }
        });

        function formatRoomAvailabilityBadge(rooms) {
            const count = parseInt(rooms ?? 0, 10);
            if (count > 0) {
                return `<span class="bg-teal-50 text-teal-700 border border-teal-200 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fa-solid fa-door-open mr-0.5"></i> Sisa ${count} kamar</span>`;
            }
            return `<span class="bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fa-solid fa-circle-xmark mr-0.5"></i> Kamar penuh</span>`;
        }

        function getFilteredKosData() {
            const q = currentCampusSearch.trim().toLowerCase();
            return kosData.filter(item => {
                const matchType = currentTypeFilter === 'All' || item.type === currentTypeFilter;
                const matchPrice = item.price <= maxPriceFilter;
                const itemCampus = String(item.campusName || '').trim();
                const matchDropdown = currentCampusDropdown === 'All' || itemCampus.toLowerCase() === currentCampusDropdown.toLowerCase();

                const haystack = `${itemCampus} ${item.name || ''} ${item.ownerName || ''} ${item.address || ''} ${item.distance || ''}`.toLowerCase();
                const matchSearch = q === '' || haystack.includes(q);

                return matchType && matchPrice && matchDropdown && matchSearch;
            });
        }

        function renderCatalogListing() {
            const container = document.getElementById('listingContainer');
            container.innerHTML = '';

            const filteredData = getFilteredKosData();
            const campusHint = currentCampusSearch.trim() !== ''
                ? ` untuk "${escapeHtml(currentCampusSearch.trim())}"`
                : (currentCampusDropdown !== 'All' ? ` dekat ${escapeHtml(currentCampusDropdown)}` : '');

            document.getElementById('resultCount').innerHTML = `Menampilkan ${filteredData.length} tempat kos${campusHint}`;

            if (filteredData.length === 0) {
                container.innerHTML = `
                    <div class="bg-white p-8 rounded-2xl text-center shadow-sm border border-slate-200">
                        <i class="fa-solid fa-house-circle-xmark text-4xl text-slate-300 mb-2"></i>
                        <p class="text-sm font-semibold text-slate-600">Tidak ada kos yang cocok dengan pencarian kampus atau filter Anda.</p>
                    </div>`;
                return;
            }

            if (!filteredData.some(k => k.id === activeCardId)) {
                activeCardId = filteredData[0].id;
                updateCatalogMap(filteredData[0].name, filteredData[0].mapQuery);
            }

            filteredData.forEach(item => {
                const isActive = item.id === activeCardId;
                const card = document.createElement('div');
                card.className = `bg-white rounded-2xl shadow-sm border ${isActive ? 'border-teal-600 bg-teal-50/20 ring-2 ring-teal-500/20' : 'border-slate-200'} overflow-hidden flex flex-col sm:flex-row hover:shadow-md transition cursor-pointer`;

                card.onclick = () => {
                    activeCardId = item.id;
                    updateCatalogMap(item.name, item.mapQuery);
                    renderCatalogListing();
                };

                const safeName = escapeHtml(item.name);
                const safeCampus = escapeHtml(item.campusName || 'Kampus');
                const safeType = escapeHtml(item.type);
                const safeDistance = escapeHtml(item.distance);
                const safePriceFormatted = escapeHtml(item.priceFormatted);
                const safeImg = escapeHtml(item.images[0]);
                const safeOwnerName = escapeHtml(item.ownerName || 'Pemilik Kos Terdaftar');

                const ownerId = parseInt(item.ownerId ?? 0, 10);
                const ownerLabelHtml = ownerId > 0
                    ? `<a href="lihat-profil.php?id=${encodeURIComponent(ownerId)}&role=pemilik" onclick="event.stopPropagation();" title="Lihat profil pemilik kos" class="font-bold text-slate-800 hover:text-teal-700 underline decoration-teal-500/40 underline-offset-2">${safeOwnerName}</a>`
                    : `<strong class="text-slate-800">${safeOwnerName}</strong>`;

                card.innerHTML = `
                    <div class="sm:w-52 h-44 sm:h-auto relative bg-slate-200 shrink-0 overflow-hidden">
                        <img src="${safeImg}" alt="${safeName}" class="w-full h-full object-cover">
                        <span class="absolute top-2 left-2 bg-teal-600 text-white text-[10px] font-bold px-2 py-1 rounded-lg">
                            ${safeType}
                        </span>
                    </div>
                    <div class="p-4 flex-1 flex flex-col justify-between space-y-3 min-w-0">
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 border border-indigo-200 text-[10px] font-bold px-2 py-0.5 rounded-full mb-1">
                                        <i class="fa-solid fa-graduation-cap"></i> ${safeCampus}
                                    </span>
                                    <h3 class="font-bold text-slate-900 text-sm sm:text-base leading-snug">${safeName}</h3>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    ${formatRoomAvailabilityBadge(item.availableRooms)}
                                    ${isActive ? '<span class="text-[10px] bg-teal-600 text-white px-2 py-0.5 rounded-full font-bold whitespace-nowrap"><i class="fa-solid fa-location-dot"></i> Peta</span>' : ''}
                                </div>
                            </div>
                            <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                <i class="fa-solid fa-person-walking text-teal-600 shrink-0"></i> <span class="truncate">${safeDistance}</span>
                            </p>
                            <p class="text-[11px] text-slate-600 mt-1 flex items-center gap-1.5">
                                <i class="fa-solid fa-user-tie text-teal-600 shrink-0"></i>
                                <span class="truncate">Pemilik: ${ownerLabelHtml}</span>
                            </p>
                            <div class="flex flex-wrap gap-1 mt-2">
                                ${item.facilities.map(f => `<span class="bg-slate-100 text-slate-600 text-[10px] px-2 py-0.5 rounded-md font-medium">${escapeHtml(f)}</span>`).join('')}
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-100">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-semibold">Tarif Bulanan</span>
                                <span class="text-teal-600 font-black text-sm">${safePriceFormatted}<span class="text-[10px] font-normal text-slate-500">/bln</span></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="text-amber-500 text-xs font-bold flex items-center gap-1">
                                    <i class="fa-solid fa-star"></i> ${escapeHtml(item.rating)}
                                </div>
                                <button onclick="event.stopPropagation(); openDetailPage(${item.id});" class="bg-teal-600 hover:bg-teal-700 text-white text-xs font-bold px-3 py-1.5 rounded-xl transition shadow-sm">
                                    Detail <i class="fa-solid fa-arrow-right text-[10px] ml-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });
        }

        function updateCampusSearch(val) {
            currentCampusSearch = String(val || '');
            renderCatalogListing();
        }

        function setCampusDropdownFilter(val) {
            currentCampusDropdown = String(val || 'All');
            renderCatalogListing();
        }

        function quickFilterCampus(campusLabel) {
            showCatalogPage();
            const selectEl = document.getElementById('campusSelectFilter');
            const inputEl = document.getElementById('campusSearchInput');
            if (selectEl) {
                const exists = Array.from(selectEl.options).some(opt => opt.value.toLowerCase() === campusLabel.toLowerCase());
                if (exists) {
                    const matchedOpt = Array.from(selectEl.options).find(opt => opt.value.toLowerCase() === campusLabel.toLowerCase());
                    selectEl.value = matchedOpt.value;
                    currentCampusDropdown = matchedOpt.value;
                    if (inputEl) inputEl.value = '';
                    currentCampusSearch = '';
                } else {
                    selectEl.value = 'All';
                    currentCampusDropdown = 'All';
                    if (inputEl) inputEl.value = campusLabel;
                    currentCampusSearch = campusLabel;
                }
            }
            renderCatalogListing();
            document.getElementById('listingContainer')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function resetAllFilters() {
            currentTypeFilter = 'All';
            currentCampusDropdown = 'All';
            currentCampusSearch = '';
            maxPriceFilter = 8000000;

            const searchEl = document.getElementById('campusSearchInput');
            const selectEl = document.getElementById('campusSelectFilter');
            const rangeEl = document.getElementById('priceRange');

            if (searchEl) searchEl.value = '';
            if (selectEl) selectEl.value = 'All';
            if (rangeEl) {
                rangeEl.value = 8000000;
                document.getElementById('priceLabel').innerText = "Rp 8.000.000";
            }

            setFilterType('All');
        }

        function updateCatalogMap(title, query) {
            document.getElementById('mapFrame').src = `https://www.google.com/maps?q=${encodeURIComponent(query)}&output=embed`;
            document.getElementById('activeMapTitle').innerText = title;
        }

        function setFilterType(type) {
            currentTypeFilter = type;
            document.querySelectorAll('.filter-type-btn').forEach(btn => {
                btn.className = "filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition";
            });
            const activeBtn = document.getElementById(`btnType${type}`);
            if (activeBtn) {
                activeBtn.className = "filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-teal-600 text-white shadow-sm transition";
            }
            renderCatalogListing();
        }

        function updatePriceFilter(value) {
            maxPriceFilter = parseInt(value, 10);
            document.getElementById('priceLabel').innerText = "Rp " + maxPriceFilter.toLocaleString('id-ID');
            renderCatalogListing();
        }

        function syncDirectBookingLink() {
            const btn = document.getElementById('btnPesanLangsung');
            const duration = document.getElementById('rentalDuration')?.value || '1';
            if (btn && selectedKosForDetail) {
                btn.href = `ajukan-sewa.php?kos_id=${encodeURIComponent(selectedKosForDetail.id)}&duration=${encodeURIComponent(duration)}`;
            }
        }

        function renderOwnerProfileInDetail(kos) {
            const nameEl = document.getElementById('detailOwnerName');
            const roleBadgeEl = document.getElementById('detailOwnerRoleBadge');
            const contactTextEl = document.getElementById('detailOwnerContactText');
            const avatarWrap = document.getElementById('detailOwnerAvatarWrap');
            const profileLinkEl = document.getElementById('detailOwnerProfileLink');
            const profileBtnEl = document.getElementById('detailOwnerProfileBtn');

            const ownerId = parseInt(kos.ownerId ?? 0, 10);
            const ownerName = String(kos.ownerName || 'Pemilik Kos Terdaftar').trim();
            const ownerRole = String(kos.ownerRole || 'pemilik').trim().toLowerCase();
            const ownerPhone = String(kos.ownerPhone || '').trim();
            const ownerAvatar = String(kos.ownerAvatar || '').trim();

            const targetProfileUrl = ownerId > 0
                ? `lihat-profil.php?id=${encodeURIComponent(ownerId)}&role=pemilik`
                : 'index.php';

            if (profileLinkEl) profileLinkEl.href = targetProfileUrl;
            if (profileBtnEl) {
                profileBtnEl.href = targetProfileUrl;
                if (ownerId <= 0) {
                    profileBtnEl.classList.add('hidden');
                } else {
                    profileBtnEl.classList.remove('hidden');
                }
            }

            if (nameEl) nameEl.innerText = ownerName;
            if (roleBadgeEl) {
                roleBadgeEl.innerText = ownerRole === 'admin' ? 'Admin / Pengelola' : 'Pemilik Kos';
            }
            if (contactTextEl) {
                contactTextEl.innerText = ownerPhone !== '' ? `Kontak: ${ownerPhone}` : 'Hubungi langsung via WhatsApp';
            }
            if (avatarWrap) {
                if (ownerAvatar !== '') {
                    avatarWrap.innerHTML = `<img src="${escapeHtml(ownerAvatar)}" alt="Foto ${escapeHtml(ownerName)}" class="w-full h-full object-cover" onerror="this.outerHTML='<i class=\\'fa-solid fa-user-tie text-lg\\'></i>';">`;
                } else {
                    avatarWrap.innerHTML = `<i class="fa-solid fa-user-tie text-lg"></i>`;
                }
            }
        }

        function openDetailPage(kosId) {
            const kos = kosData.find(k => k.id === kosId);
            if (!kos) return;
            selectedKosForDetail = kos;

            const campusLabel = kos.campusName || 'Kampus';
            document.getElementById('detailBreadcrumbCampus').innerText = campusLabel;
            document.getElementById('detailBreadcrumbTitle').innerText = kos.name;
            document.getElementById('detailCampusText').innerText = campusLabel;
            document.getElementById('detailTitle').innerText = kos.name;

            renderOwnerProfileInDetail(kos);

            const addrWrap = document.getElementById('detailAddress');
            const addrTextEl = document.getElementById('detailAddressText');
            const cleanAddr = String(kos.address || '').trim();
            if (cleanAddr !== '' && cleanAddr !== '0') {
                if (addrTextEl) addrTextEl.innerText = cleanAddr;
                if (addrWrap) addrWrap.classList.remove('hidden');
            } else {
                if (addrTextEl) addrTextEl.innerText = '';
                if (addrWrap) addrWrap.classList.add('hidden');
            }

            const roomsCount = parseInt(kos.availableRooms ?? 0, 10);
            const roomsBadge = document.getElementById('detailAvailableRoomsBadge');
            const roomsText = document.getElementById('detailAvailableRoomsText');
            if (roomsBadge && roomsText) {
                if (roomsCount > 0) {
                    roomsText.innerText = `Sisa ${roomsCount} Kamar`;
                    roomsBadge.className = 'inline-flex items-center gap-1 bg-teal-50 text-teal-700 px-3 py-1.5 rounded-xl border border-teal-200 font-bold text-xs sm:text-sm';
                } else {
                    roomsText.innerText = 'Kamar Penuh';
                    roomsBadge.className = 'inline-flex items-center gap-1 bg-rose-50 text-rose-700 px-3 py-1.5 rounded-xl border border-rose-200 font-bold text-xs sm:text-sm';
                }
            }

            document.getElementById('detailRating').innerText = kos.rating;
            document.getElementById('detailBadgeType').innerText = kos.type;
            document.getElementById('detailSpecSize').innerText = kos.size;
            document.getElementById('detailSpecElectricity').innerText = kos.electricity;
            document.getElementById('detailSpecPdam').innerText = kos.pdam || 'Sudah Termasuk Biaya Kos';
            document.getElementById('detailSpecSecurity').innerText = kos.security || 'CCTV 24 Jam';
            document.getElementById('detailSpecDistance').innerText = kos.distance;
            document.getElementById('detailDescription').innerText = kos.description;
            document.getElementById('detailPrice').innerText = kos.priceFormatted;

            const images = Array.isArray(kos.images) && kos.images.length > 0 ? kos.images : ['https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80'];
            document.getElementById('detailMainImage').src = images[0];
            const thumbsContainer = document.getElementById('detailGalleryThumbs');
            thumbsContainer.innerHTML = '';
            images.forEach((imgUrl, index) => {
                const thumb = document.createElement('img');
                thumb.src = imgUrl;
                thumb.alt = `${kos.name} foto ${index + 1}`;
                thumb.className = `h-14 sm:h-16 w-full object-cover rounded-lg cursor-pointer border-2 transition ${index === 0 ? 'border-teal-600' : 'border-transparent hover:border-slate-300'}`;
                thumb.onclick = () => {
                    document.getElementById('detailMainImage').src = imgUrl;
                    thumbsContainer.querySelectorAll('img').forEach((el, idx) => {
                        el.className = `h-14 sm:h-16 w-full object-cover rounded-lg cursor-pointer border-2 transition ${idx === index ? 'border-teal-600' : 'border-transparent hover:border-slate-300'}`;
                    });
                };
                thumbsContainer.appendChild(thumb);
            });

            const facContainer = document.getElementById('detailFacilitiesGrid');
            facContainer.innerHTML = kos.facilities.map(f => `
                <div class="flex items-center gap-2 bg-slate-50 p-2.5 rounded-xl border border-slate-100 text-xs font-medium text-slate-700">
                    <i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i> <span>${escapeHtml(f)}</span>
                </div>
            `).join('');

            const rulesListEl = document.getElementById('detailRulesList');
            const customRules = String(kos.rules || '')
                .split(/\r?\n/)
                .map(r => r.trim())
                .filter(r => r !== '');

            const rulesToRender = customRules.length > 0
                ? customRules
                : [
                    'Tamu lawan jenis dilarang masuk ke dalam kamar.',
                    'Jam malam pagar utama dikunci pukul 23:00 WIB.',
                    'Menjaga kebersihan area umum & dapur bersama.'
                  ];

            if (rulesListEl) {
                rulesListEl.innerHTML = rulesToRender.map(ruleText => `
                    <li class="flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i>
                        <span>${escapeHtml(ruleText)}</span>
                    </li>
                `).join('');
            }

            document.getElementById('detailMapFrame').src = `https://www.google.com/maps?q=${encodeURIComponent(kos.mapQuery)}&output=embed`;

            document.getElementById('reviewKosId').value = kos.id;
            const reviews = Array.isArray(kos.reviews) ? kos.reviews : [];
            document.getElementById('detailReviewCount').innerText = `${reviews.length} Ulasan`;

            const reviewsContainer = document.getElementById('detailReviewsList');
            if (reviews.length === 0) {
                reviewsContainer.innerHTML = `
                    <div class="text-center py-6 bg-slate-50 rounded-xl border border-slate-100 text-xs text-slate-400">
                        Belum ada ulasan untuk kos ini. Jadilah yang pertama memberikan ulasan!
                    </div>
                `;
            } else {
                reviewsContainer.innerHTML = reviews.map(rev => {
                    const stars = Array.from({ length: 5 }, (_, idx) =>
                        `<i class="fa-${idx < rev.rating ? 'solid' : 'regular'} fa-star text-amber-400"></i>`
                    ).join('');
                    return `
                        <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-1.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-xs text-slate-900">${escapeHtml(rev.reviewerName)}</span>
                                    <span class="text-[11px]">${stars} (${escapeHtml(rev.rating)}/5)</span>
                                </div>
                                <span class="text-[11px] text-slate-400">${escapeHtml(rev.createdAt || '')}</span>
                            </div>
                            <p class="text-xs text-slate-600 leading-relaxed">${escapeHtml(rev.comment)}</p>
                        </div>
                    `;
                }).join('');
            }

            document.getElementById('rentalDuration').value = "1";
            calculateTotalPrice();

            document.getElementById('catalogPage').classList.add('hidden');
            document.getElementById('detailPage').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function showCatalogPage() {
            document.getElementById('detailPage').classList.add('hidden');
            document.getElementById('catalogPage').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function calculateTotalPrice() {
            if (!selectedKosForDetail) return;
            const duration = parseInt(document.getElementById('rentalDuration').value, 10);
            let monthlyPrice = selectedKosForDetail.price;
            let discountPercentage = 0;

            if (duration === 3) discountPercentage = 0.02;
            else if (duration === 6) discountPercentage = 0.05;
            else if (duration === 12) discountPercentage = 0.10;

            const totalBeforeDiscount = monthlyPrice * duration;
            const finalTotal = totalBeforeDiscount * (1 - discountPercentage);

            document.getElementById('calculatedTotal').innerText = "Rp " + finalTotal.toLocaleString('id-ID');
            syncDirectBookingLink();
        }

        function formatWhatsAppNumber(rawPhone) {
            let digits = String(rawPhone || '').replace(/\D/g, '');
            if (!digits) {
                return '6281260007890';
            }
            if (digits.startsWith('0')) {
                digits = '62' + digits.slice(1);
            }
            return digits;
        }

        function bookViaWhatsApp() {
            if (!selectedKosForDetail) return;
            const duration = document.getElementById('rentalDuration').value;
            const totalText = document.getElementById('calculatedTotal').innerText;
            const phone = formatWhatsAppNumber(selectedKosForDetail.ownerWhatsapp);

            const message = `Halo ${selectedKosForDetail.ownerName || 'Pemilik'}, saya tertarik untuk menyewa kos di *${selectedKosForDetail.name}* (${selectedKosForDetail.campusName || 'Kampus'}) melalui KosFind.\n\n` +
                            `• *Tipe:* Kos ${selectedKosForDetail.type}\n` +
                            `• *Durasi Sewa:* ${duration} Bulan\n` +
                            `• *Estimasi Total:* ${totalText}\n\n` +
                            `Apakah unit kamar masih tersedia untuk disurvei/ditempati? Terima kasih!`;

            const waUrl = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
            const popup = window.open(waUrl, '_blank', 'noopener,noreferrer');
            if (!popup) {
                window.location.href = waUrl;
            }
        }
    </script>
</body>
</html>