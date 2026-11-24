<?php
require_once 'config/db.php';
require_once 'config/auth.php';

requireRole(['pemilik', 'admin'], 'login.php');
$user = currentUser();
$userId = (int)$user['id'];

$kosId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($kosId <= 0) {
    header("Location: kos-saya.php");
    exit;
}

// Deteksi nama kolom yang tersedia pada tabel kos
$columns = [];
$colRes = mysqli_query($conn, "SHOW COLUMNS FROM kos");
if ($colRes) {
    while ($c = mysqli_fetch_assoc($colRes)) {
        $columns[] = $c['Field'];
    }
}

$hasOwnerId = in_array('owner_id', $columns, true);
$hasRulesCol = in_array('rules', $columns, true);
$hasMapQueryCol = in_array('map_query', $columns, true);
$hasGalleryCol = in_array('gallery_images', $columns, true);
$hasCampusCol = in_array('campus_name', $columns, true);
$hasPdamCol = in_array('pdam', $columns, true);
$hasSecurityCol = in_array('security', $columns, true);
$addrCol = in_array('address', $columns, true) ? 'address' : (in_array('alamat', $columns, true) ? 'alamat' : null);
$roomsCol = in_array('available_rooms', $columns, true) ? 'available_rooms' : (in_array('sisa_kamar', $columns, true) ? 'sisa_kamar' : null);

$photoCol = null;
foreach (['main_image', 'image', 'photo', 'foto', 'gambar', 'image_url'] as $candidateCol) {
    if (in_array($candidateCol, $columns, true)) {
        $photoCol = $candidateCol;
        break;
    }
}

// Ambil data kos target
$stmt = mysqli_prepare($conn, "SELECT * FROM kos WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $kosId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$kos = $res ? mysqli_fetch_assoc($res) : null;

if (!$kos) {
    header("Location: kos-saya.php");
    exit;
}

// Validasi kepemilikan jika role adalah pemilik
if ($user['role'] === 'pemilik' && $hasOwnerId) {
    if ((int)($kos['owner_id'] ?? 0) !== $userId) {
        header("Location: kos-saya.php");
        exit;
    }
}

function normalizeGalleryList(array $kosRow, ?string $photoColumn, bool $hasGalleryColumn): array
{
    $list = [];
    if ($hasGalleryColumn && !empty($kosRow['gallery_images'])) {
        $decoded = json_decode((string)$kosRow['gallery_images'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $p) {
                $pClean = trim((string)$p);
                if ($pClean !== '' && !in_array($pClean, $list, true)) {
                    $list[] = $pClean;
                }
            }
        }
    }
    if ($photoColumn !== null && !empty($kosRow[$photoColumn])) {
        $mainClean = trim((string)$kosRow[$photoColumn]);
        if ($mainClean !== '' && !in_array($mainClean, $list, true)) {
            array_unshift($list, $mainClean);
        }
    }
    return $list;
}

function normalizeCampusList(array $checkedCampuses, string $customCampusRaw): array
{
    $result = [];
    foreach ($checkedCampuses as $item) {
        $clean = trim((string)preg_replace('/\s+/', ' ', (string)$item));
        if ($clean !== '') {
            $exists = false;
            foreach ($result as $existing) {
                if (strcasecmp($existing, $clean) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $result[] = $clean;
            }
        }
    }

    if ($customCampusRaw !== '') {
        $parts = explode(',', $customCampusRaw);
        foreach ($parts as $part) {
            $clean = trim((string)preg_replace('/\s+/', ' ', $part));
            if ($clean !== '') {
                $exists = false;
                foreach ($result as $existing) {
                    if (strcasecmp($existing, $clean) === 0) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $result[] = $clean;
                }
            }
        }
    }

    return $result;
}

$errorMsg = '';
$presetCampuses = ['USU', 'Unimed', 'Polmed'];
$electricityPresets = ['Token Sendiri', 'Sudah Termasuk Biaya Kos', 'Iuran Bulanan', 'Bayar Sendiri'];
$pdamPresets = ['Sudah Termasuk Biaya Kos', 'Bayar Sendiri', 'Iuran Bulanan'];
$securityPresets = ['CCTV 24 Jam', 'Penjaga Kos & CCTV', 'Gerbang Terkunci / Akses Kunci'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $postedCampusList = isset($_POST['campus_list']) && is_array($_POST['campus_list']) ? $_POST['campus_list'] : [];
    $campusCustom = trim((string)($_POST['campus_custom'] ?? ''));
    $campusArr = normalizeCampusList($postedCampusList, $campusCustom);
    $campusName = implode(', ', $campusArr);

    $elecSelect = trim((string)($_POST['electricity_select'] ?? 'Token Sendiri'));
    $elecCustom = trim((string)($_POST['electricity_custom'] ?? ''));
    $electricity = ($elecSelect === '__other__') ? $elecCustom : $elecSelect;
    $electricity = trim((string)preg_replace('/\s+/', ' ', $electricity));
    if ($electricity === '') {
        $electricity = 'Token Sendiri';
    }

    $type = trim((string)($_POST['type'] ?? 'Campur'));
    $price = max(0, (int)($_POST['price'] ?? 0));
    $distance = trim((string)($_POST['distance'] ?? ''));
    $addressVal = trim((string)($_POST['address'] ?? ''));
    $size = trim((string)($_POST['size'] ?? ''));
    $pdamVal = trim((string)($_POST['pdam'] ?? 'Sudah Termasuk Biaya Kos'));
    $securityVal = trim((string)($_POST['security'] ?? 'CCTV 24 Jam'));
    $facilities = trim((string)($_POST['facilities'] ?? ''));
    $ownerWhatsapp = trim((string)($_POST['owner_whatsapp'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $rulesVal = trim((string)($_POST['rules'] ?? ''));
    $availableRooms = max(0, (int)($_POST['available_rooms'] ?? 0));
    $replaceGallery = isset($_POST['replace_gallery']) && $_POST['replace_gallery'] === '1';

    $existingPhotos = normalizeGalleryList($kos, $photoCol, $hasGalleryCol);
    $currentPhotoPath = $photoCol !== null ? (string)($kos[$photoCol] ?? '') : '';
    $newlyUploadedPaths = [];

    if ($name === '' || $price <= 0) {
        $errorMsg = 'Nama kos dan tarif per bulan wajib diisi dengan benar.';
    } elseif ($hasCampusCol && $campusName === '') {
        $errorMsg = 'Minimal satu nama kampus terdekat wajib dipilih atau diisi.';
    }

    // Proses upload banyak foto kos jika dipilih dari galeri
    if ($errorMsg === '' && isset($_FILES['kos_photos']) && isset($_FILES['kos_photos']['name']) && is_array($_FILES['kos_photos']['name'])) {
        $fileCount = count($_FILES['kos_photos']['name']);
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $uploadDir = __DIR__ . '/uploads/kos';

        for ($i = 0; $i < $fileCount; $i++) {
            $errCode = $_FILES['kos_photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($errCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errCode !== UPLOAD_ERR_OK) {
                $errorMsg = 'Salah satu foto gagal diunggah. Silakan pilih ulang.';
                break;
            }
            $fileSize = (int)($_FILES['kos_photos']['size'][$i] ?? 0);
            if ($fileSize > 3 * 1024 * 1024) {
                $errorMsg = 'Ukuran setiap foto kos maksimal 3 MB.';
                break;
            }
            $tmpName = (string)($_FILES['kos_photos']['tmp_name'][$i] ?? '');
            $mime = $tmpName !== '' ? $finfo->file($tmpName) : '';
            if (!isset($allowedMimes[$mime])) {
                $errorMsg = 'Format foto kos harus JPG, PNG, atau WEBP.';
                break;
            }

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $ext = $allowedMimes[$mime];
            $fileName = 'kos_' . $kosId . '_' . time() . '_' . $i . '_' . bin2hex(random_bytes(2)) . '.' . $ext;
            $targetFile = $uploadDir . '/' . $fileName;

            if (move_uploaded_file($tmpName, $targetFile)) {
                $newlyUploadedPaths[] = 'uploads/kos/' . $fileName;
            } else {
                $errorMsg = 'Gagal menyimpan salah satu file foto ke folder uploads/kos.';
                break;
            }
        }
    }

    if ($errorMsg !== '' && !empty($newlyUploadedPaths)) {
        foreach ($newlyUploadedPaths as $upPath) {
            @unlink(__DIR__ . '/' . $upPath);
        }
    }

    $finalGallery = $existingPhotos;
    if (!empty($newlyUploadedPaths)) {
        if ($replaceGallery) {
            $finalGallery = $newlyUploadedPaths;
        } else {
            foreach ($newlyUploadedPaths as $upPath) {
                if (!in_array($upPath, $finalGallery, true)) {
                    $finalGallery[] = $upPath;
                }
            }
        }
    }

    $newMainImage = !empty($finalGallery) ? $finalGallery[0] : $currentPhotoPath;

    if ($errorMsg === '') {
        $finalPdam = $pdamVal !== '' ? $pdamVal : 'Sudah Termasuk Biaya Kos';
        $finalSecurity = $securityVal !== '' ? $securityVal : 'CCTV 24 Jam';

        $setParts = [
            "name = ?",
            "type = ?",
            "price = ?",
            "distance = ?",
            "size = ?",
            "electricity = ?",
            "facilities = ?",
            "owner_whatsapp = ?",
            "description = ?"
        ];
        $types = "ssissssss";
        $params = [
            $name,
            $type,
            $price,
            $distance,
            $size,
            $electricity,
            $facilities,
            $ownerWhatsapp,
            $description
        ];

        if ($hasPdamCol) {
            $setParts[] = "pdam = ?";
            $types .= "s";
            $params[] = $finalPdam;
        }

        if ($hasSecurityCol) {
            $setParts[] = "security = ?";
            $types .= "s";
            $params[] = $finalSecurity;
        }

        if ($hasCampusCol) {
            $setParts[] = "campus_name = ?";
            $types .= "s";
            $params[] = $campusName;
        }

        if ($hasRulesCol) {
            $setParts[] = "rules = ?";
            $types .= "s";
            $params[] = $rulesVal;
        }

        if ($addrCol !== null) {
            $setParts[] = "{$addrCol} = ?";
            $types .= "s";
            $params[] = $addressVal;
        }

        if ($hasMapQueryCol && $addressVal !== '') {
            $setParts[] = "map_query = ?";
            $types .= "s";
            $params[] = $addressVal;
        }

        if ($roomsCol !== null) {
            $setParts[] = "{$roomsCol} = ?";
            $types .= "i";
            $params[] = $availableRooms;
        }

        if ($photoCol !== null && !empty($newlyUploadedPaths)) {
            $setParts[] = "{$photoCol} = ?";
            $types .= "s";
            $params[] = $newMainImage;
        }

        if ($hasGalleryCol && !empty($newlyUploadedPaths)) {
            $setParts[] = "gallery_images = ?";
            $types .= "s";
            $params[] = json_encode($finalGallery, JSON_UNESCAPED_SLASHES);
        }

        $sqlUpdate = "UPDATE kos SET " . implode(", ", $setParts) . " WHERE id = ?";
        $types .= "i";
        $params[] = $kosId;

        if ($user['role'] === 'pemilik' && $hasOwnerId) {
            $sqlUpdate .= " AND owner_id = ?";
            $types .= "i";
            $params[] = $userId;
        }

        $stmtUpd = mysqli_prepare($conn, $sqlUpdate);
        if ($stmtUpd) {
            mysqli_stmt_bind_param($stmtUpd, $types, ...$params);
            if (mysqli_stmt_execute($stmtUpd)) {
                if ($replaceGallery && !empty($newlyUploadedPaths)) {
                    foreach ($existingPhotos as $oldPath) {
                        if (!in_array($oldPath, $finalGallery, true) && strpos($oldPath, 'uploads/') === 0) {
                            $fullOld = __DIR__ . '/' . $oldPath;
                            if (is_file($fullOld)) {
                                @unlink($fullOld);
                            }
                        }
                    }
                }
                header("Location: kos-saya.php?updated=1");
                exit;
            } else {
                foreach ($newlyUploadedPaths as $upPath) {
                    @unlink(__DIR__ . '/' . $upPath);
                }
                $errorMsg = 'Gagal menyimpan perubahan: ' . htmlspecialchars(mysqli_error($conn));
            }
        } else {
            foreach ($newlyUploadedPaths as $upPath) {
                @unlink(__DIR__ . '/' . $upPath);
            }
            $errorMsg = 'Gagal menyiapkan query pembaruan data.';
        }
    }

    // Perbarui nilai form jika ada error validasi
    $kos['name'] = $name;
    if ($hasCampusCol) $kos['campus_name'] = $campusName;
    $kos['type'] = $type;
    $kos['price'] = $price;
    $kos['distance'] = $distance;
    if ($addrCol !== null) $kos[$addrCol] = $addressVal;
    $kos['size'] = $size;
    $kos['electricity'] = $electricity;
    if ($hasPdamCol) $kos['pdam'] = $pdamVal;
    if ($hasSecurityCol) $kos['security'] = $securityVal;
    $kos['facilities'] = $facilities;
    $kos['owner_whatsapp'] = $ownerWhatsapp;
    $kos['description'] = $description;
    if ($hasRulesCol) $kos['rules'] = $rulesVal;
    if ($roomsCol !== null) $kos[$roomsCol] = $availableRooms;
}

$currentAddr = $addrCol !== null ? (string)($kos[$addrCol] ?? '') : '';
if ($currentAddr === '0') $currentAddr = '';
$currentRooms = $roomsCol !== null ? (int)($kos[$roomsCol] ?? 0) : 0;
$currentRules = $hasRulesCol ? (string)($kos['rules'] ?? '') : '';
$currentGalleryList = normalizeGalleryList($kos, $photoCol, $hasGalleryCol);

$currentCampus = $hasCampusCol ? trim((string)($kos['campus_name'] ?? '')) : '';
if ($currentCampus === '') {
    $currentCampus = 'USU';
}
$currentCampusParts = array_values(array_filter(array_map('trim', explode(',', $currentCampus))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selCampusList = isset($_POST['campus_list']) && is_array($_POST['campus_list'])
        ? array_map('strval', $_POST['campus_list'])
        : [];
    $selCampusCustom = (string)($_POST['campus_custom'] ?? '');
} else {
    $selCampusList = [];
    $customParts = [];
    foreach ($currentCampusParts as $cPart) {
        $matchedPreset = null;
        foreach ($presetCampuses as $pCam) {
            if (strcasecmp($cPart, $pCam) === 0) {
                $matchedPreset = $pCam;
                break;
            }
        }
        if ($matchedPreset !== null) {
            $selCampusList[] = $matchedPreset;
        } else {
            $customParts[] = $cPart;
        }
    }
    $selCampusCustom = implode(', ', $customParts);
}

$currentElec = trim((string)($kos['electricity'] ?? ''));
if ($currentElec === '') {
    $currentElec = 'Token Sendiri';
}
$isPresetElec = in_array($currentElec, $electricityPresets, true);
if (isset($_POST['electricity_select'])) {
    $selElecSelect = (string)$_POST['electricity_select'];
    $selElecCustom = (string)($_POST['electricity_custom'] ?? '');
} else {
    $selElecSelect = $isPresetElec ? $currentElec : '__other__';
    $selElecCustom = $isPresetElec ? '' : $currentElec;
}

$currentPdam = $hasPdamCol && trim((string)($kos['pdam'] ?? '')) !== ''
    ? trim((string)$kos['pdam'])
    : 'Sudah Termasuk Biaya Kos';

$currentSecurity = $hasSecurityCol && trim((string)($kos['security'] ?? '')) !== ''
    ? trim((string)$kos['security'])
    : 'CCTV 24 Jam';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Kos - KosFind</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen py-6 sm:py-10 px-3 sm:px-4">
    <div class="max-w-3xl mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-8">
        <div class="flex flex-wrap items-center justify-between gap-3 pb-5 border-b border-slate-100">
            <div>
                <h1 class="text-lg sm:text-xl font-extrabold text-slate-900">Edit Data Kos</h1>
                <p class="text-xs text-slate-500 mt-1">
                    Perbarui foto kos (bisa lebih dari 1 foto), kampus, listrik, PDAM, keamanan, harga, maupun peraturan kos.
                </p>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <a href="kos-saya.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 rounded-xl transition">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Kembali ke Kos Saya
                </a>
            </div>
        </div>

        <?php if ($errorMsg !== ''): ?>
            <div class="mt-4 bg-rose-50 border border-rose-200 text-rose-700 text-xs px-4 py-3 rounded-xl font-semibold">
                <?= $errorMsg ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasGalleryCol): ?>
            <div class="mt-4 bg-amber-50 border border-amber-200 text-amber-800 text-xs px-4 py-3 rounded-xl">
                Kolom <code>gallery_images</code> belum ditemukan di tabel <code>kos</code>. Jalankan <code>ALTER TABLE kos ADD COLUMN gallery_images TEXT NULL AFTER main_image;</code> agar banyak foto tersimpan di galeri kos.
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="mt-6 space-y-4 text-xs">
            <input type="hidden" name="id" value="<?= (int)$kos['id'] ?>">

            <!-- UPLOAD BANYAK FOTO KOS -->
            <div>
                <label class="font-bold text-slate-700 block mb-1.5">Foto Kos (Bisa Pilih Lebih dari 1 Foto dari Galeri)</label>
                <div class="bg-slate-50 p-3.5 rounded-2xl border border-slate-200 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="kosPhotosInput" class="inline-flex items-center gap-1.5 bg-white hover:bg-teal-50 text-teal-700 border border-teal-200 font-bold px-3.5 py-2 rounded-xl cursor-pointer transition">
                                <i class="fa-solid fa-images"></i> Pilih Foto Kos (Multiple)
                            </label>
                            <input id="kosPhotosInput" type="file" name="kos_photos[]" multiple accept="image/jpeg,image/png,image/webp,image/*" class="hidden" onchange="previewKosPhotos(this)">
                        </div>
                        <label class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-600 cursor-pointer">
                            <input type="checkbox" name="replace_gallery" value="1" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span>Ganti semua foto lama dengan pilihan baru</span>
                        </label>
                    </div>
                    <p id="kosPhotosName" class="text-[11px] text-slate-500">
                        JPG, PNG, atau WEBP • Maksimal 3 MB per foto • Foto pertama akan menjadi foto utama di katalog
                    </p>

                    <!-- PREVIEW FOTO TERPILIH / FOTO SAAT INI -->
                    <div id="kosPhotosPreviewGrid" class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-1">
                        <?php foreach ($currentGalleryList as $idx => $imgItem): ?>
                            <div class="relative rounded-xl overflow-hidden border border-slate-300 bg-white h-24">
                                <img src="<?= htmlspecialchars($imgItem) ?>" alt="Foto Kos <?= $idx + 1 ?>" class="w-full h-full object-cover">
                                <?php if ($idx === 0): ?>
                                    <span class="absolute top-1.5 left-1.5 bg-teal-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-md">Utama</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Nama Kos *</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars((string)($kos['name'] ?? '')) ?>"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Nama Kampus Terdekat (Bisa lebih dari 1) *</label>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($presetCampuses as $pCampus): ?>
                            <?php $isChecked = in_array($pCampus, $selCampusList, true); ?>
                            <label class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl border border-slate-200 bg-slate-50 hover:bg-teal-50/50 cursor-pointer font-semibold text-slate-700">
                                <input type="checkbox" name="campus_list[]" value="<?= htmlspecialchars($pCampus) ?>" <?= $isChecked ? 'checked' : '' ?> class="accent-teal-600 rounded">
                                <span><?= htmlspecialchars($pCampus) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="text" name="campus_custom"
                        value="<?= htmlspecialchars($selCampusCustom) ?>"
                        placeholder="Kampus lain / tambahan (pisahkan koma jika >1)"
                        class="mt-2 w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Tipe Gender *</label>
                    <?php $curType = (string)($kos['type'] ?? 'Campur'); ?>
                    <select name="type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                        <option value="Putri" <?= $curType === 'Putri' ? 'selected' : '' ?>>Putri</option>
                        <option value="Putra" <?= $curType === 'Putra' ? 'selected' : '' ?>>Putra</option>
                        <option value="Campur" <?= $curType === 'Campur' ? 'selected' : '' ?>>Campur</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Tarif / Bulan (Rp) *</label>
                    <input type="number" name="price" min="0" step="10000" required value="<?= (int)($kos['price'] ?? 0) ?>"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Sisa Kamar Tersedia</label>
                    <input type="number" name="available_rooms" min="0" step="1" value="<?= $currentRooms ?>"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-teal-200 bg-teal-50/40 font-bold text-teal-800 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Jarak ke Kampus</label>
                    <input type="text" name="distance" value="<?= htmlspecialchars((string)($kos['distance'] ?? '')) ?>"
                           placeholder="Contoh: 300m dari Kampus"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Alamat Lengkap</label>
                <input type="text" name="address" value="<?= htmlspecialchars($currentAddr) ?>"
                       placeholder="Contoh: Jl. Dr. Mansyur No. 12, Medan"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Ukuran Kamar</label>
                    <input type="text" name="size" value="<?= htmlspecialchars((string)($kos['size'] ?? '')) ?>"
                           placeholder="Contoh: 3 x 4 Meter"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Listrik</label>
                    <select id="electricitySelect" name="electricity_select" onchange="toggleCustomInput('electricitySelect', 'electricityCustomInput')" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                        <?php foreach ($electricityPresets as $eOpt): ?>
                            <option value="<?= htmlspecialchars($eOpt) ?>" <?= $selElecSelect === $eOpt ? 'selected' : '' ?>><?= htmlspecialchars($eOpt) ?></option>
                        <?php endforeach; ?>
                        <option value="__other__" <?= $selElecSelect === '__other__' ? 'selected' : '' ?>>Lainnya (Ketik Sendiri)...</option>
                    </select>
                    <input id="electricityCustomInput" type="text" name="electricity_custom"
                        value="<?= htmlspecialchars($selElecCustom) ?>"
                        placeholder="Contoh: Token Bersama atau Include 100kWh"
                        class="<?= $selElecSelect === '__other__' ? '' : 'hidden' ?> mt-2 w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="font-bold text-slate-700 block mb-1">PDAM / Air</label>
                    <select name="pdam" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                        <?php foreach ($pdamPresets as $pOpt): ?>
                            <option value="<?= htmlspecialchars($pOpt) ?>" <?= $currentPdam === $pOpt ? 'selected' : '' ?>><?= htmlspecialchars($pOpt) ?></option>
                        <?php endforeach; ?>
                        <?php if (!in_array($currentPdam, $pdamPresets, true)): ?>
                            <option value="<?= htmlspecialchars($currentPdam) ?>" selected><?= htmlspecialchars($currentPdam) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Keamanan</label>
                    <select name="security" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                        <?php foreach ($securityPresets as $sOpt): ?>
                            <option value="<?= htmlspecialchars($sOpt) ?>" <?= $currentSecurity === $sOpt ? 'selected' : '' ?>><?= htmlspecialchars($sOpt) ?></option>
                        <?php endforeach; ?>
                        <?php if (!in_array($currentSecurity, $securityPresets, true)): ?>
                            <option value="<?= htmlspecialchars($currentSecurity) ?>" selected><?= htmlspecialchars($currentSecurity) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Nomor WhatsApp Pemilik</label>
                    <input type="text" name="owner_whatsapp" value="<?= htmlspecialchars((string)($kos['owner_whatsapp'] ?? '')) ?>"
                           placeholder="Contoh: 081260007890"
                           class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Fasilitas (pisahkan dengan koma)</label>
                <input type="text" name="facilities" value="<?= htmlspecialchars((string)($kos['facilities'] ?? '')) ?>"
                       placeholder="Wi-Fi, Kasur, Lemari, KM Dalam, AC"
                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Deskripsi Hunian</label>
                <textarea name="description" rows="3"
                          class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none"><?= htmlspecialchars((string)($kos['description'] ?? '')) ?></textarea>
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Peraturan Kos (1 baris = 1 peraturan)</label>
                <textarea name="rules" rows="4"
                          placeholder="Contoh:&#10;Tamu lawan jenis dilarang masuk ke dalam kamar.&#10;Jam malam pagar utama dikunci pukul 23:00 WIB.&#10;Menjaga kebersihan area umum & dapur bersama."
                          class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none"><?= htmlspecialchars($currentRules) ?></textarea>
            </div>

            <div class="pt-2 flex flex-wrap items-center justify-end gap-2">
                <a href="kos-saya.php" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">
                    Batal
                </a>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-600 hover:bg-teal-700 text-white font-bold shadow-sm transition">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>

    <script>
        function toggleCustomInput(selectId, customInputId) {
            const sel = document.getElementById(selectId);
            const customInput = document.getElementById(customInputId);
            if (!sel || !customInput) return;
            if (sel.value === '__other__') {
                customInput.classList.remove('hidden');
                customInput.required = true;
                customInput.focus();
            } else {
                customInput.classList.add('hidden');
                customInput.required = false;
                customInput.value = '';
            }
        }

        function previewKosPhotos(input) {
            const nameEl = document.getElementById('kosPhotosName');
            const gridEl = document.getElementById('kosPhotosPreviewGrid');
            if (!input.files || input.files.length === 0) return;

            const files = Array.from(input.files);
            if (nameEl) {
                nameEl.innerText = `Dipilih ${files.length} foto: ` + files.map(f => f.name).join(', ');
            }

            if (gridEl) {
                gridEl.innerHTML = '';
                files.forEach((file, idx) => {
                    const card = document.createElement('div');
                    card.className = 'relative rounded-xl overflow-hidden border border-teal-300 bg-white h-24';

                    const img = document.createElement('img');
                    img.className = 'w-full h-full object-cover';
                    img.alt = 'Preview ' + (idx + 1);

                    const reader = new FileReader();
                    reader.onload = function (e) {
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);

                    const badge = document.createElement('span');
                    badge.className = 'absolute top-1.5 left-1.5 bg-teal-600 text-white text-[10px] font-bold px-2 py-0.5 rounded-md';
                    badge.innerText = idx === 0 ? 'Baru #1' : `Baru #${idx + 1}`;

                    card.appendChild(img);
                    card.appendChild(badge);
                    gridEl.appendChild(card);
                });
            }
        }
    </script>
</body>
</html>