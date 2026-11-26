<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

$user = currentUser();
$menuBadgeCount = 0;

if ($user && !empty($user['id'])) {
    $uid = (int)$user['id'];
    $roleLower = strtolower($user['role'] ?? 'user');
    $menuBadgeCount = getUnreadBadgeCount($conn, $uid, $roleLower);

    if ($roleLower === 'admin' || $roleLower === 'pemilik') {
        $cekBookings = mysqli_query($conn, "SHOW TABLES LIKE 'bookings'");
        if ($cekBookings && mysqli_num_rows($cekBookings) > 0) {
            $sqlPending = ($roleLower === 'admin')
                ? "SELECT COUNT(*) AS cnt FROM bookings WHERE LOWER(status) = 'pending'"
                : "SELECT COUNT(*) AS cnt FROM bookings b JOIN kos k ON b.kos_id = k.id WHERE LOWER(b.status) = 'pending' AND k.owner_id = $uid";

            $resPending = mysqli_query($conn, $sqlPending);
            if ($resPending && $rowPending = mysqli_fetch_assoc($resPending)) {
                $menuBadgeCount = max($menuBadgeCount, (int)($rowPending['cnt'] ?? 0));
            }
        }
    }
}

// Cek kolom tabel kos & users
$kosCols = [];
$resKosCol = mysqli_query($conn, "SHOW COLUMNS FROM kos");
while ($resKosCol && $c = mysqli_fetch_assoc($resKosCol)) {
    $kosCols[] = $c['Field'];
}

$roomsCol = in_array('available_rooms', $kosCols, true)
    ? 'available_rooms'
    : (in_array('sisa_kamar', $kosCols, true) ? 'sisa_kamar' : null);

$hasRulesCol = in_array('rules', $kosCols, true);
$hasGalleryCol = in_array('gallery_images', $kosCols, true);
$hasCampusCol = in_array('campus_name', $kosCols, true);
$hasPdamCol = in_array('pdam', $kosCols, true);
$hasSecurityCol = in_array('security', $kosCols, true);
$hasOwnerIdCol = in_array('owner_id', $kosCols, true);

$userCols = [];
$hasUsersTable = false;
$cekUsers = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
if ($cekUsers && mysqli_num_rows($cekUsers) > 0) {
    $hasUsersTable = true;
    $resUserCol = mysqli_query($conn, "SHOW COLUMNS FROM users");
    while ($resUserCol && $uc = mysqli_fetch_assoc($resUserCol)) {
        $userCols[] = $uc['Field'];
    }
}

// Ambil data kos
if ($hasOwnerIdCol && $hasUsersTable) {
    $extraUserCols = '';
    foreach (['avatar', 'phone', 'whatsapp'] as $col) {
        if (in_array($col, $userCols, true)) {
            $extraUserCols .= ", u.$col AS owner_user_$col";
        }
    }
    $query = "SELECT k.*, u.name AS owner_user_name, u.role AS owner_user_role$extraUserCols
              FROM kos k LEFT JOIN users u ON k.owner_id = u.id ORDER BY k.id ASC";
} else {
    $query = "SELECT * FROM kos ORDER BY id ASC";
}
$result = mysqli_query($conn, $query);

// Ambil semua ulasan per kos
$reviewsMap = [];
$revResult = mysqli_query($conn, "SELECT id, kos_id, reviewer_name, rating, comment, created_at FROM reviews ORDER BY id DESC");
while ($revResult && $revRow = mysqli_fetch_assoc($revResult)) {
    $kId = (int)$revRow['kos_id'];
    $reviewsMap[$kId][] = [
        'id' => (int)$revRow['id'],
        'reviewerName' => $revRow['reviewer_name'],
        'rating' => (int)$revRow['rating'],
        'comment' => $revRow['comment'],
        'createdAt' => date('d M Y', strtotime($revRow['created_at']))
    ];
}

// Susun daftar kos & opsi kampus
$kosList = [];
$campusOptions = [];

while ($result && $row = mysqli_fetch_assoc($result)) {
    $kosId = (int)$row['id'];
    $facilitiesArray = array_values(array_filter(array_map('trim', explode(',', $row['facilities'] ?? ''))));

    $rawAddress = trim((string)($row['address'] ?? $row['alamat'] ?? ''));
    $cleanAddress = ($rawAddress === '0') ? '' : $rawAddress;
    $availableRooms = $roomsCol ? max(0, (int)($row[$roomsCol] ?? 0)) : 1;
    $campusName = $hasCampusCol ? trim((string)($row['campus_name'] ?? '')) : '';

    $ownerName = trim((string)($row['owner_user_name'] ?? $row['owner_name'] ?? ''));
    if ($ownerName === '') {
        $ownerName = 'Pemilik Kos Terdaftar';
    }

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

    $defaultMapTarget = $campusName !== '' ? ('Kampus ' . $campusName) : ($cleanAddress !== '' ? $cleanAddress : 'Indonesia');

    $kosList[] = [
        'id' => $kosId,
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
        'pdam' => ($hasPdamCol && trim((string)($row['pdam'] ?? '')) !== '') ? trim((string)$row['pdam']) : 'Sudah Termasuk Biaya Kos',
        'security' => ($hasSecurityCol && trim((string)($row['security'] ?? '')) !== '') ? trim((string)$row['security']) : 'CCTV 24 Jam',
        'description' => $row['description'],
        'rules' => $hasRulesCol ? (string)($row['rules'] ?? '') : '',
        'images' => $galleryList,
        'facilities' => $facilitiesArray,
        'mapQuery' => ($row['map_query'] ?: $cleanAddress) ?: $defaultMapTarget,
        'ownerId' => (int)($row['owner_id'] ?? 0),
        'ownerName' => $ownerName,
        'ownerRole' => trim((string)($row['owner_user_role'] ?? 'pemilik')),
        'ownerAvatar' => trim((string)($row['owner_user_avatar'] ?? $row['owner_avatar'] ?? '')),
        'ownerPhone' => trim((string)($row['owner_user_phone'] ?? $row['owner_user_whatsapp'] ?? $row['owner_whatsapp'] ?? '')),
        'ownerWhatsapp' => $ownerWhatsapp,
        'reviews' => $reviewsMap[$kosId] ?? []
    ];

    foreach (explode(',', $campusName) as $cPart) {
        $cName = trim($cPart);
        if ($cName !== '') {
            $exists = false;
            foreach ($campusOptions as $opt) {
                if (strcasecmp($opt, $cName) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $campusOptions[] = $cName;
            }
        }
    }
}
natcasesort($campusOptions);
$campusOptions = array_values($campusOptions);

$avatarSrc = '';
if (is_array($user)) {
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

    <!-- NAVBAR -->
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
                <button type="button" id="navMenuToggle" onclick="toggleNavMenu(event)"
                    class="inline-flex items-center gap-2 bg-slate-100 hover:bg-teal-50 text-slate-700 hover:text-teal-700 border border-slate-200 hover:border-teal-200 px-3.5 py-2 rounded-xl text-xs font-bold shadow-sm transition cursor-pointer">
                    <i class="fa-solid fa-bars text-sm"></i>
                    <span>Menu</span>
                    <?php if ($menuBadgeCount > 0): ?>
                        <span class="bg-rose-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full leading-none">+<?= $menuBadgeCount ?></span>
                    <?php endif; ?>
                    <i id="navMenuChevron" class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"></i>
                </button>

                <div id="navDropdownList" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-slate-200 py-2 z-50 text-xs">
                    <?php if (!$user): ?>
                        <div class="px-3.5 py-2 border-b border-slate-100">
                            <p class="text-[11px] font-semibold text-slate-400">Belum masuk akun</p>
                        </div>
                        <div class="p-1.5 space-y-1">
                            <a href="login.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-700 hover:bg-teal-50 hover:text-teal-700 transition">
                                <i class="fa-solid fa-right-to-bracket w-4 text-center text-teal-600"></i><span>Masuk</span>
                            </a>
                            <a href="register.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-white bg-teal-600 hover:bg-teal-700 transition">
                                <i class="fa-solid fa-user-plus w-4 text-center"></i><span>Daftar Akun</span>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="px-3.5 py-2.5 border-b border-slate-100 flex items-center gap-2.5 bg-slate-50/70">
                            <?php if ($avatarSrc !== ''): ?>
                                <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Foto <?= htmlspecialchars($user['name']) ?>"
                                    class="w-8 h-8 rounded-full object-cover border border-teal-200 shrink-0"
                                    onerror="this.outerHTML='<div class=\'w-8 h-8 rounded-full bg-teal-100 text-teal-700 flex items-center justify-center shrink-0\'><i class=\'fa-solid fa-circle-user text-base\'></i></div>';">
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
                                    <i class="fa-solid fa-building-user w-4 text-center"></i><span>Kos Saya</span>
                                </a>
                                <a href="daftarkan-kos.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-teal-700 hover:bg-teal-50 transition">
                                    <i class="fa-solid fa-plus-circle w-4 text-center"></i><span>Daftarkan Kos</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($user['role'] === 'pemilik' || $user['role'] === 'admin'): ?>
                                <a href="pesanan.php" class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl font-bold text-indigo-700 hover:bg-indigo-50 transition">
                                    <span class="flex items-center gap-2.5"><i class="fa-solid fa-receipt w-4 text-center"></i><span>Pesanan Masuk</span></span>
                                    <?php if ($menuBadgeCount > 0): ?>
                                        <span class="bg-rose-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full leading-none">+<?= $menuBadgeCount ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php else: ?>
                                <a href="pesanan-user.php" class="flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl font-bold text-indigo-700 hover:bg-indigo-50 transition">
                                    <span class="flex items-center gap-2.5"><i class="fa-solid fa-receipt w-4 text-center"></i><span>Pesanan User</span></span>
                                    <?php if ($menuBadgeCount > 0): ?>
                                        <span class="bg-emerald-600 text-white text-[10px] font-extrabold px-1.5 py-0.5 rounded-full leading-none">+<?= $menuBadgeCount ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($user['role'] === 'admin'): ?>
                                <a href="admin/index.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-amber-700 hover:bg-amber-50 transition">
                                    <i class="fa-solid fa-sliders w-4 text-center"></i><span>CMS Admin</span>
                                </a>
                            <?php endif; ?>

                            <a href="profil.php" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-slate-700 hover:bg-slate-100 transition">
                                <i class="fa-solid fa-user-pen w-4 text-center text-slate-500"></i><span>Profil</span>
                            </a>
                        </div>

                        <div class="p-1.5 pt-1 border-t border-slate-100">
                            <a href="logout.php" onclick="return confirm('Yakin ingin keluar dari akun?');" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl font-bold text-rose-600 hover:bg-rose-50 transition">
                                <i class="fa-solid fa-right-from-bracket w-4 text-center"></i><span>Logout</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- HALAMAN KATALOG -->
    <div id="catalogPage" class="flex-1 w-full">
        <main class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-6 sm:py-8">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-4 sm:p-5 mb-6 sm:mb-8 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
                    <div class="md:col-span-6 relative">
                        <i class="fa-solid fa-magnifying-glass text-slate-400 text-xs absolute left-3.5 top-1/2 -translate-y-1/2"></i>
                        <input type="text" id="campusSearchInput" oninput="updateCampusSearch(this.value)"
                            placeholder="Cari nama kampus, nama kos, alamat, atau pemilik..."
                            class="w-full pl-9 pr-4 py-2 text-xs rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>

                    <div class="md:col-span-4 relative" id="multiCampusDropdownWrapper">
                        <button type="button" id="multiCampusToggleBtn" onclick="toggleMultiCampusDropdown(event)"
                            class="w-full px-3.5 py-2 text-xs font-bold text-slate-700 rounded-xl border border-slate-200 bg-slate-50 hover:bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none flex items-center justify-between gap-2 transition cursor-pointer">
                            <span id="multiCampusLabel" class="truncate text-left">Semua Kampus Terdaftar</span>
                            <i class="fa-solid fa-chevron-down text-[10px] text-slate-400 shrink-0"></i>
                        </button>

                        <div id="multiCampusDropdownPanel" class="hidden absolute left-0 right-0 mt-1.5 bg-white rounded-2xl shadow-xl border border-slate-200 p-2.5 z-30 max-h-60 overflow-y-auto text-xs space-y-1">
                            <?php if (empty($campusOptions)): ?>
                                <p class="text-[11px] text-slate-400 px-2 py-1.5">Belum ada data kampus terdaftar.</p>
                            <?php else: ?>
                                <?php foreach ($campusOptions as $cOpt): ?>
                                    <label class="flex items-center gap-2 px-2.5 py-1.5 rounded-xl hover:bg-teal-50/60 cursor-pointer font-semibold text-slate-700">
                                        <input type="checkbox" value="<?= htmlspecialchars($cOpt) ?>" onchange="toggleSelectedCampus(this.value, this.checked)" class="campus-multi-checkbox accent-teal-600 rounded">
                                        <span class="truncate"><?= htmlspecialchars($cOpt) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="md:col-span-2 flex justify-end">
                        <button type="button" onclick="resetAllFilters()"
                            class="w-full px-3 py-2 text-xs font-bold text-slate-600 hover:text-teal-700 bg-slate-100 hover:bg-teal-50 border border-slate-200 rounded-xl transition cursor-pointer">
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
                                Lokasi Kos
                            </span>
                        </div>
                        <div class="map-container h-64 sm:h-80 lg:h-[420px] overflow-hidden rounded-xl bg-slate-100 relative">
                            <iframe id="mapFrame" src="https://www.google.com/maps?q=Indonesia&output=embed" allowfullscreen="" loading="lazy"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- HALAMAN DETAIL KOS -->
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
                            <span id="detailBadgeType" class="absolute top-3 left-3 sm:top-4 sm:left-4 bg-teal-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm">Putri</span>
                        </div>
                        <div id="detailGalleryThumbs" class="grid grid-cols-4 sm:grid-cols-6 gap-2"></div>
                    </div>

                    <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-sm border border-slate-200 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 pb-4">
                            <div>
                                <div id="detailCampusBadgesWrap" class="flex flex-wrap items-center gap-1.5 mb-1"></div>
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
                                        <span id="detailOwnerRoleBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">Pemilik Kos</span>
                                    </div>
                                    <p id="detailOwnerName" class="text-sm font-extrabold text-slate-900 group-hover:text-teal-700 underline decoration-teal-500/40 underline-offset-2 truncate mt-0.5">Nama Pemilik</p>
                                    <p id="detailOwnerContact" class="text-[11px] text-slate-500 truncate mt-0.5">
                                        <i class="fa-solid fa-phone text-teal-600 mr-1"></i><span id="detailOwnerContactText">Kontak tersedia via WA</span>
                                    </p>
                                </div>
                            </a>
                            <div class="flex items-center gap-2 shrink-0">
                                <a id="detailOwnerProfileBtn" href="index.php" class="inline-flex items-center gap-1 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 px-3 py-2 rounded-xl text-xs font-bold transition">
                                    <span>Profil</span><i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
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
                            <ul id="detailRulesList" class="space-y-2 text-xs text-slate-600"></ul>
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
                            <span id="detailReviewCount" class="text-xs font-bold text-teal-700 bg-teal-50 px-3 py-1 rounded-full border border-teal-100">0 Ulasan</span>
                        </div>

                        <form action="tambah-ulasan.php" method="POST" class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3 text-xs">
                            <input type="hidden" name="kos_id" id="reviewKosId" value="">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="font-bold text-slate-700 block mb-1">Nama Pengulas *</label>
                                    <input type="text" name="reviewer_name" required value="<?= htmlspecialchars($user['name'] ?? '') ?>" placeholder="Nama kamu / mahasiswa"
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
                                <textarea name="comment" rows="3" required placeholder="Ceritakan kondisi kamar, Wi-Fi, air/listrik, keamanan, atau jarak ke kampus..."
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

                        <select id="rentalDuration" onchange="calculateTotalPrice()" class="w-full text-xs font-semibold p-3 rounded-xl border border-slate-200 bg-slate-50 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <option value="1">1 Bulan</option>
                            <option value="3">3 Bulan</option>
                            <option value="6">6 Bulan</option>
                            <option value="12">12 Bulan</option>
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
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <footer class="bg-slate-900 text-slate-400 py-6 text-xs mt-auto">
        <div class="max-w-7xl mx-auto px-4 text-center space-y-0.5">
            <p><i class="fa-solid fa-envelope mr-1"></i> info@kosfind.ac.id</p>
            <p><i class="fa-solid fa-phone mr-1"></i> +62 812-6000-7890</p>
        </div>
            <div class="max-w-7xl mx-auto px-4 border-t border-slate-800 mt-5 pt-4 text-center text-slate-500">
            &copy; 2026 KosFind. All rights reserved.
        </div>
    </footer>

    <script>
        const kosData = <?= json_encode($kosList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        let currentTypeFilter = 'All';
        let selectedCampusesFilter = [];
        let currentCampusSearch = '';
        let maxPriceFilter = 8000000;
        let activeCardId = kosData.length > 0 ? kosData[0].id : null;
        let selectedKosForDetail = null;

        const $id = (id) => document.getElementById(id);

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function splitCampusList(rawCampus) {
            return String(rawCampus || '').split(',').map(c => c.trim()).filter(Boolean);
        }

        function renderCampusBadgesHtml(rawCampus) {
            return splitCampusList(rawCampus).map(c => `
                <span class="inline-flex items-center gap-1 bg-indigo-50 text-indigo-700 border border-indigo-200 text-[10px] font-bold px-2 py-0.5 rounded-full">
                    <i class="fa-solid fa-graduation-cap"></i> ${escapeHtml(c)}
                </span>
            `).join(' ');
        }

        function toggleNavMenu(event) {
            if (event) event.stopPropagation();
            const dropdown = $id('navDropdownList');
            const chevron = $id('navMenuChevron');
            if (!dropdown) return;
            const willOpen = dropdown.classList.contains('hidden');
            dropdown.classList.toggle('hidden', !willOpen);
            if (chevron) chevron.classList.toggle('rotate-180', willOpen);
        }

        function closeNavMenu() {
            const dropdown = $id('navDropdownList');
            const chevron = $id('navMenuChevron');
            if (dropdown) dropdown.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }

        function toggleMultiCampusDropdown(event) {
            if (event) event.stopPropagation();
            const panel = $id('multiCampusDropdownPanel');
            if (panel) panel.classList.toggle('hidden');
        }

        function closeMultiCampusDropdown() {
            const panel = $id('multiCampusDropdownPanel');
            if (panel) panel.classList.add('hidden');
        }

        function syncMultiCampusLabel() {
            const labelEl = $id('multiCampusLabel');
            if (!labelEl) return;
            if (selectedCampusesFilter.length === 0) labelEl.innerText = 'Semua Kampus Terdaftar';
            else if (selectedCampusesFilter.length === 1) labelEl.innerText = selectedCampusesFilter[0];
            else labelEl.innerText = `${selectedCampusesFilter.length} Kampus Dipilih`;
        }

        function toggleSelectedCampus(campusName, isChecked) {
            const clean = String(campusName || '').trim();
            if (!clean) return;
            if (isChecked) {
                if (!selectedCampusesFilter.some(c => c.toLowerCase() === clean.toLowerCase())) {
                    selectedCampusesFilter.push(clean);
                }
            } else {
                selectedCampusesFilter = selectedCampusesFilter.filter(c => c.toLowerCase() !== clean.toLowerCase());
            }
            syncMultiCampusLabel();
            renderCatalogListing();
        }

        document.addEventListener('click', (e) => {
            if (!$id('navMenuWrapper')?.contains(e.target)) closeNavMenu();
            if (!$id('multiCampusDropdownWrapper')?.contains(e.target)) closeMultiCampusDropdown();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeNavMenu();
                closeMultiCampusDropdown();
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const priceRangeEl = $id('priceRange');
            if (priceRangeEl) {
                maxPriceFilter = parseInt(priceRangeEl.value, 10) || 8000000;
                if ($id('priceLabel')) $id('priceLabel').innerText = 'Rp ' + maxPriceFilter.toLocaleString('id-ID');
            }

            renderCatalogListing();
            if (kosData.length > 0) updateCatalogMap(kosData[0].name, kosData[0].mapQuery);

            const openKosId = parseInt(new URLSearchParams(window.location.search).get('open_kos') || '0', 10);
            if (openKosId > 0 && kosData.some(k => k.id === openKosId)) openDetailPage(openKosId);
        });

        function formatRoomAvailabilityBadge(rooms) {
            const count = parseInt(rooms ?? 0, 10);
            return count > 0
                ? `<span class="bg-teal-50 text-teal-700 border border-teal-200 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fa-solid fa-door-open mr-0.5"></i> Sisa ${count} kamar</span>`
                : `<span class="bg-rose-50 text-rose-700 border border-rose-200 text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap"><i class="fa-solid fa-circle-xmark mr-0.5"></i> Kamar penuh</span>`;
        }

        function getFilteredKosData() {
            const q = currentCampusSearch.trim().toLowerCase();
            return kosData.filter(item => {
                const matchType = currentTypeFilter === 'All' || item.type === currentTypeFilter;
                const matchPrice = item.price <= maxPriceFilter;
                const itemCampuses = splitCampusList(item.campusName).map(c => c.toLowerCase());
                const matchCampus = selectedCampusesFilter.length === 0 ||
                    selectedCampusesFilter.some(sel => itemCampuses.includes(sel.toLowerCase()));
                const haystack = `${item.campusName} ${item.name} ${item.ownerName} ${item.address} ${item.distance}`.toLowerCase();
                return matchType && matchPrice && matchCampus && (q === '' || haystack.includes(q));
            });
        }

        function renderCatalogListing() {
            const container = $id('listingContainer');
            if (!container) return;
            container.innerHTML = '';

            const filteredData = getFilteredKosData();
            let campusHint = '';
            if (currentCampusSearch.trim() !== '') campusHint = ` untuk "${escapeHtml(currentCampusSearch.trim())}"`;
            else if (selectedCampusesFilter.length > 0) campusHint = ` dekat ${escapeHtml(selectedCampusesFilter.join(', '))}`;

            if ($id('resultCount')) {
                $id('resultCount').innerHTML = `Menampilkan ${filteredData.length} tempat kos${campusHint}`;
            }

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

                const safeOwner = escapeHtml(item.ownerName || 'Pemilik Kos Terdaftar');
                const ownerId = parseInt(item.ownerId ?? 0, 10);
                const ownerLink = ownerId > 0
                    ? `<a href="lihat-profil.php?id=${ownerId}&role=pemilik" onclick="event.stopPropagation();" class="font-bold text-slate-800 hover:text-teal-700 underline decoration-teal-500/40 underline-offset-2">${safeOwner}</a>`
                    : `<strong class="text-slate-800">${safeOwner}</strong>`;

                const campusBadges = renderCampusBadgesHtml(item.campusName);

                card.innerHTML = `
                    <div class="sm:w-52 h-44 sm:h-auto relative bg-slate-200 shrink-0 overflow-hidden">
                        <img src="${escapeHtml(item.images[0])}" alt="${escapeHtml(item.name)}" class="w-full h-full object-cover">
                        <span class="absolute top-2 left-2 bg-teal-600 text-white text-[10px] font-bold px-2 py-1 rounded-lg">${escapeHtml(item.type)}</span>
                    </div>
                    <div class="p-4 flex-1 flex flex-col justify-between space-y-3 min-w-0">
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    ${campusBadges ? `<div class="flex flex-wrap items-center gap-1 mb-1">${campusBadges}</div>` : ''}
                                    <h3 class="font-bold text-slate-900 text-sm sm:text-base leading-snug">${escapeHtml(item.name)}</h3>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    ${formatRoomAvailabilityBadge(item.availableRooms)}
                                    ${isActive ? '<span class="text-[10px] bg-teal-600 text-white px-2 py-0.5 rounded-full font-bold whitespace-nowrap"><i class="fa-solid fa-location-dot"></i> Peta</span>' : ''}
                                </div>
                            </div>
                            <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                <i class="fa-solid fa-person-walking text-teal-600 shrink-0"></i> <span class="truncate">${escapeHtml(item.distance)}</span>
                            </p>
                            <p class="text-[11px] text-slate-600 mt-1 flex items-center gap-1.5">
                                <i class="fa-solid fa-user-tie text-teal-600 shrink-0"></i> <span class="truncate">Pemilik: ${ownerLink}</span>
                            </p>
                            <div class="flex flex-wrap gap-1 mt-2">
                                ${item.facilities.map(f => `<span class="bg-slate-100 text-slate-600 text-[10px] px-2 py-0.5 rounded-md font-medium">${escapeHtml(f)}</span>`).join('')}
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-slate-100">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-semibold">Tarif Bulanan</span>
                                <span class="text-teal-600 font-black text-sm">${escapeHtml(item.priceFormatted)}<span class="text-[10px] font-normal text-slate-500">/bln</span></span>
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
                    </div>`;
                container.appendChild(card);
            });
        }

        function updateCampusSearch(val) {
            currentCampusSearch = String(val || '');
            renderCatalogListing();
        }

        function resetAllFilters() {
            currentTypeFilter = 'All';
            selectedCampusesFilter = [];
            currentCampusSearch = '';
            maxPriceFilter = 8000000;

            document.querySelectorAll('.campus-multi-checkbox').forEach(cb => cb.checked = false);
            syncMultiCampusLabel();

            if ($id('campusSearchInput')) $id('campusSearchInput').value = '';
            if ($id('priceRange')) $id('priceRange').value = 8000000;
            if ($id('priceLabel')) $id('priceLabel').innerText = 'Rp 8.000.000';

            setFilterType('All');
        }

        function updateCatalogMap(title, query) {
            if ($id('mapFrame')) $id('mapFrame').src = `https://www.google.com/maps?q=${encodeURIComponent(query)}&output=embed`;
            if ($id('activeMapTitle')) $id('activeMapTitle').innerText = title;
        }

        function setFilterType(type) {
            currentTypeFilter = type;
            document.querySelectorAll('.filter-type-btn').forEach(btn => {
                btn.className = 'filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-slate-100 text-slate-600 hover:bg-slate-200 transition';
            });
            const activeBtn = $id(`btnType${type}`);
            if (activeBtn) {
                activeBtn.className = 'filter-type-btn px-3 sm:px-4 py-1.5 sm:py-2 text-xs font-bold rounded-xl bg-teal-600 text-white shadow-sm transition';
            }
            renderCatalogListing();
        }

        function updatePriceFilter(value) {
            maxPriceFilter = parseInt(value, 10);
            if ($id('priceLabel')) $id('priceLabel').innerText = 'Rp ' + maxPriceFilter.toLocaleString('id-ID');
            renderCatalogListing();
        }

        function openDetailPage(kosId) {
            const kos = kosData.find(k => k.id === kosId);
            if (!kos) return;
            selectedKosForDetail = kos;

            const campusLabel = kos.campusName || 'Kampus';
            $id('detailBreadcrumbCampus').innerText = campusLabel;
            $id('detailBreadcrumbTitle').innerText = kos.name;
            $id('detailCampusBadgesWrap').innerHTML = renderCampusBadgesHtml(campusLabel);
            $id('detailTitle').innerText = kos.name;

            // Info pemilik kos
            const ownerId = parseInt(kos.ownerId ?? 0, 10);
            const ownerName = kos.ownerName || 'Pemilik Kos Terdaftar';
            const profileUrl = ownerId > 0 ? `lihat-profil.php?id=${ownerId}&role=pemilik` : 'index.php';

            $id('detailOwnerProfileLink').href = profileUrl;
            $id('detailOwnerProfileBtn').href = profileUrl;
            $id('detailOwnerProfileBtn').classList.toggle('hidden', ownerId <= 0);
            $id('detailOwnerName').innerText = ownerName;
            $id('detailOwnerRoleBadge').innerText = (kos.ownerRole || '').toLowerCase() === 'admin' ? 'Admin / Pengelola' : 'Pemilik Kos';
            $id('detailOwnerContactText').innerText = kos.ownerPhone ? `Kontak: ${kos.ownerPhone}` : 'Hubungi langsung via WhatsApp';
            $id('detailOwnerAvatarWrap').innerHTML = kos.ownerAvatar
                ? `<img src="${escapeHtml(kos.ownerAvatar)}" alt="Foto ${escapeHtml(ownerName)}" class="w-full h-full object-cover" onerror="this.outerHTML='<i class=\\'fa-solid fa-user-tie text-lg\\'></i>';">`
                : `<i class="fa-solid fa-user-tie text-lg"></i>`;

            // Alamat & ketersediaan kamar
            const cleanAddr = String(kos.address || '').trim();
            $id('detailAddressText').innerText = cleanAddr;
            $id('detailAddress').classList.toggle('hidden', cleanAddr === '' || cleanAddr === '0');

            const roomsCount = parseInt(kos.availableRooms ?? 0, 10);
            $id('detailAvailableRoomsText').innerText = roomsCount > 0 ? `Sisa ${roomsCount} Kamar` : 'Kamar Penuh';
            $id('detailAvailableRoomsBadge').className = roomsCount > 0
                ? 'inline-flex items-center gap-1 bg-teal-50 text-teal-700 px-3 py-1.5 rounded-xl border border-teal-200 font-bold text-xs sm:text-sm'
                : 'inline-flex items-center gap-1 bg-rose-50 text-rose-700 px-3 py-1.5 rounded-xl border border-rose-200 font-bold text-xs sm:text-sm';

            // Spesifikasi & harga
            $id('detailRating').innerText = kos.rating;
            $id('detailBadgeType').innerText = kos.type;
            $id('detailSpecSize').innerText = kos.size;
            $id('detailSpecElectricity').innerText = kos.electricity;
            $id('detailSpecPdam').innerText = kos.pdam || 'Sudah Termasuk Biaya Kos';
            $id('detailSpecSecurity').innerText = kos.security || 'CCTV 24 Jam';
            $id('detailSpecDistance').innerText = kos.distance;
            $id('detailDescription').innerText = kos.description;
            $id('detailPrice').innerText = kos.priceFormatted;

            // Galeri foto
            const images = kos.images?.length ? kos.images : ['https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80'];
            $id('detailMainImage').src = images[0];
            const thumbs = $id('detailGalleryThumbs');
            thumbs.innerHTML = '';
            images.forEach((imgUrl, idx) => {
                const thumb = document.createElement('img');
                thumb.src = imgUrl;
                thumb.className = `h-14 sm:h-16 w-full object-cover rounded-lg cursor-pointer border-2 transition ${idx === 0 ? 'border-teal-600' : 'border-transparent hover:border-slate-300'}`;
                thumb.onclick = () => {
                    $id('detailMainImage').src = imgUrl;
                    thumbs.querySelectorAll('img').forEach((el, i) => {
                        el.className = `h-14 sm:h-16 w-full object-cover rounded-lg cursor-pointer border-2 transition ${i === idx ? 'border-teal-600' : 'border-transparent hover:border-slate-300'}`;
                    });
                };
                thumbs.appendChild(thumb);
            });

            // Fasilitas & peraturan
            $id('detailFacilitiesGrid').innerHTML = kos.facilities.map(f => `
                <div class="flex items-center gap-2 bg-slate-50 p-2.5 rounded-xl border border-slate-100 text-xs font-medium text-slate-700">
                    <i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i> <span>${escapeHtml(f)}</span>
                </div>
            `).join('');

            const customRules = String(kos.rules || '').split(/\r?\n/).map(r => r.trim()).filter(Boolean);
            const rulesList = customRules.length ? customRules : [
                'Tamu lawan jenis dilarang masuk ke dalam kamar.',
                'Jam malam pagar utama dikunci pukul 23:00 WIB.',
                'Menjaga kebersihan area umum & dapur bersama.'
            ];
            $id('detailRulesList').innerHTML = rulesList.map(r => `
                <li class="flex items-center gap-2"><i class="fa-solid fa-circle-check text-teal-600 shrink-0"></i><span>${escapeHtml(r)}</span></li>
            `).join('');

            $id('detailMapFrame').src = `https://www.google.com/maps?q=${encodeURIComponent(kos.mapQuery)}&output=embed`;

            // Ulasan
            $id('reviewKosId').value = kos.id;
            const reviews = Array.isArray(kos.reviews) ? kos.reviews : [];
            $id('detailReviewCount').innerText = `${reviews.length} Ulasan`;
            $id('detailReviewsList').innerHTML = reviews.length === 0
                ? `<div class="text-center py-6 bg-slate-50 rounded-xl border border-slate-100 text-xs text-slate-400">Belum ada ulasan untuk kos ini. Jadilah yang pertama memberikan ulasan!</div>`
                : reviews.map(rev => {
                    const stars = Array.from({ length: 5 }, (_, i) => `<i class="fa-${i < rev.rating ? 'solid' : 'regular'} fa-star text-amber-400"></i>`).join('');
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
                        </div>`;
                }).join('');

            $id('rentalDuration').value = '1';
            calculateTotalPrice();

            $id('catalogPage').classList.add('hidden');
            $id('detailPage').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function showCatalogPage() {
            $id('detailPage').classList.add('hidden');
            $id('catalogPage').classList.remove('hidden');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function calculateTotalPrice() {
            if (!selectedKosForDetail) return;
            const duration = parseInt($id('rentalDuration')?.value || '1', 10);
            const total = selectedKosForDetail.price * duration;

            $id('calculatedTotal').innerText = 'Rp ' + total.toLocaleString('id-ID');
            $id('btnPesanLangsung').href = `ajukan-sewa.php?kos_id=${selectedKosForDetail.id}&duration=${duration}`;
        }

        function bookViaWhatsApp() {
            if (!selectedKosForDetail) return;
            const duration = $id('rentalDuration')?.value || '1';
            const totalText = $id('calculatedTotal')?.innerText || '';
            let phone = String(selectedKosForDetail.ownerWhatsapp || '').replace(/\D/g, '') || '6281260007890';
            if (phone.startsWith('0')) phone = '62' + phone.slice(1);

            const message = `Halo ${selectedKosForDetail.ownerName || 'Pemilik'}, saya tertarik menyewa kos *${selectedKosForDetail.name}* (${selectedKosForDetail.campusName || 'Kampus'}) melalui KosFind.\n\n` +
                            `• Tipe: Kos ${selectedKosForDetail.type}\n` +
                            `• Durasi Sewa: ${duration} Bulan\n` +
                            `• Estimasi Total: ${totalText}\n\n` +
                            `Apakah kamar masih tersedia? Terima kasih!`;

            window.open(`https://wa.me/${phone}?text=${encodeURIComponent(message)}`, '_blank', 'noopener,noreferrer');
        }
    </script>
</body>
</html>