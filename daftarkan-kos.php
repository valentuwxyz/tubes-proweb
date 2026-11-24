<?php
require_once 'config/db.php';
require_once 'config/auth.php';

requireRole(['pemilik', 'admin'], 'login.php');
$user = currentUser();

$error = '';
$success = '';

// Deteksi kolom pada tabel kos secara dinamis
$hasRulesCol = false;
$hasCampusCol = false;
$hasPdamCol = false;
$hasSecurityCol = false;
$roomsCol = null;
$kColRes = mysqli_query($conn, "SHOW COLUMNS FROM kos");
if ($kColRes) {
    while ($c = mysqli_fetch_assoc($kColRes)) {
        if ($c['Field'] === 'rules') {
            $hasRulesCol = true;
        }
        if ($c['Field'] === 'campus_name') {
            $hasCampusCol = true;
        }
        if ($c['Field'] === 'pdam') {
            $hasPdamCol = true;
        }
        if ($c['Field'] === 'security') {
            $hasSecurityCol = true;
        }
        if ($c['Field'] === 'available_rooms') {
            $roomsCol = 'available_rooms';
        } elseif ($c['Field'] === 'sisa_kamar' && $roomsCol === null) {
            $roomsCol = 'sisa_kamar';
        }
    }
}

function normalizeCampusInputs($campusInputs): array
{
    $result = [];
    $items = is_array($campusInputs) ? $campusInputs : [$campusInputs];

    foreach ($items as $rawItem) {
        $parts = explode(',', (string)$rawItem);
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

$electricityPresets = ['Token Sendiri', 'Sudah Termasuk Biaya Kos', 'Iuran Bulanan', 'Bayar Sendiri'];
$pdamPresets = ['Sudah Termasuk Biaya Kos', 'Bayar Sendiri', 'Iuran Bulanan'];
$securityPresets = ['CCTV 24 Jam', 'Penjaga Kos & CCTV', 'Gerbang Terkunci / Akses Kunci'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $postedCampuses = $_POST['campuses'] ?? ($_POST['campus_name'] ?? []);
    $campusArr = normalizeCampusInputs($postedCampuses);
    $campusName = implode(', ', $campusArr);

    $elecSelect = trim((string)($_POST['electricity_select'] ?? 'Token Sendiri'));
    $elecCustom = trim((string)($_POST['electricity_custom'] ?? ''));
    $electricity = ($elecSelect === '__other__') ? $elecCustom : $elecSelect;
    $electricity = trim((string)preg_replace('/\s+/', ' ', $electricity));
    if ($electricity === '') {
        $electricity = 'Token Sendiri';
    }

    $type = trim($_POST['type'] ?? 'Putri');
    $price = (int)($_POST['price'] ?? 0);
    $availableRooms = max(0, (int)($_POST['available_rooms'] ?? 1));
    $distance = trim($_POST['distance'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $rating = (float)($_POST['rating'] ?? 4.8);
    $size = trim($_POST['size'] ?? '3 x 4 Meter');
    $pdam = trim($_POST['pdam'] ?? 'Sudah Termasuk Biaya Kos');
    $security = trim($_POST['security'] ?? 'CCTV 24 Jam');
    $description = trim($_POST['description'] ?? '');
    $rules = trim($_POST['rules'] ?? '');
    $facilities = trim($_POST['facilities'] ?? '');
    $mapQuery = trim($_POST['map_query'] ?? '');
    $ownerWhatsapp = preg_replace('/[^0-9]/', '', $_POST['owner_whatsapp'] ?? ($user['phone'] ?? '6281260007890'));

    if (!in_array($type, ['Putri', 'Putra', 'Campur'], true)) {
        $type = 'Putri';
    }

    if ($name === '' || $campusName === '' || $price <= 0 || $distance === '' || $address === '') {
        $error = "Nama kos, minimal satu nama kampus terdekat, harga bulanan, jarak kampus, dan alamat lengkap wajib diisi.";
    } else {
        $finalImage = 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80';

        // Proses upload foto dari galeri / file explorer jika ada file dipilih
        if (isset($_FILES['kos_photo']) && $_FILES['kos_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['kos_photo']['error'] !== UPLOAD_ERR_OK) {
                $error = "Terjadi kesalahan saat mengunggah foto kos. Coba pilih ulang gambar.";
            } elseif ($_FILES['kos_photo']['size'] > 3 * 1024 * 1024) {
                $error = "Ukuran foto kos maksimal 3 MB.";
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['kos_photo']['tmp_name']);
                $allowedMimes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowedMimes[$mime])) {
                    $error = "Format foto harus JPG, PNG, atau WEBP.";
                } else {
                    $uploadDir = __DIR__ . '/uploads/kos';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $ext = $allowedMimes[$mime];
                    $fileName = 'kos_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $fileName;

                    if (move_uploaded_file($_FILES['kos_photo']['tmp_name'], $targetPath)) {
                        $finalImage = 'uploads/kos/' . $fileName;
                    } else {
                        $error = "Gagal menyimpan file foto ke folder uploads/kos.";
                    }
                }
            }
        }

        if ($error === '') {
            $finalMapQuery = $mapQuery !== '' ? $mapQuery : ($address . ', ' . $campusName);
            $finalFacilities = $facilities !== '' ? $facilities : 'Kasur, Lemari, Wi-Fi';
            $finalDesc = $description !== '' ? $description : ("Hunian nyaman di area kampus " . $campusName . ".");
            $finalPdam = $pdam !== '' ? $pdam : 'Sudah Termasuk Biaya Kos';
            $finalSecurity = $security !== '' ? $security : 'CCTV 24 Jam';
            $ownerId = (int)$user['id'];

            // Susun kolom & nilai INSERT secara dinamis mengikuti struktur tabel kos
            $insertCols = ['name', 'type', 'price', 'distance', 'address', 'rating', 'size', 'electricity', 'description', 'main_image', 'facilities', 'map_query', 'owner_whatsapp'];
            $placeholders = array_fill(0, count($insertCols), '?');
            $types = 'ssisdssssssss';
            $params = [$name, $type, $price, $distance, $address, $rating, $size, $electricity, $finalDesc, $finalImage, $finalFacilities, $finalMapQuery, $ownerWhatsapp];

            if ($hasPdamCol) {
                $insertCols[] = 'pdam';
                $placeholders[] = '?';
                $types .= 's';
                $params[] = $finalPdam;
            }
            if ($hasSecurityCol) {
                $insertCols[] = 'security';
                $placeholders[] = '?';
                $types .= 's';
                $params[] = $finalSecurity;
            }
            if ($hasCampusCol) {
                $insertCols[] = 'campus_name';
                $placeholders[] = '?';
                $types .= 's';
                $params[] = $campusName;
            }
            if ($hasRulesCol) {
                $insertCols[] = 'rules';
                $placeholders[] = '?';
                $types .= 's';
                $params[] = $rules;
            }
            if ($roomsCol !== null) {
                $insertCols[] = $roomsCol;
                $placeholders[] = '?';
                $types .= 'i';
                $params[] = $availableRooms;
            }

            // Coba sertakan owner_id terlebih dahulu
            $colsWithOwner = array_merge($insertCols, ['owner_id']);
            $phWithOwner = array_merge($placeholders, ['?']);
            $sqlWithOwner = "INSERT INTO kos (" . implode(', ', $colsWithOwner) . ") VALUES (" . implode(', ', $phWithOwner) . ")";
            $stmt = mysqli_prepare($conn, $sqlWithOwner);

            if ($stmt) {
                $typesWithOwner = $types . 'i';
                $paramsWithOwner = array_merge($params, [$ownerId]);
                mysqli_stmt_bind_param($stmt, $typesWithOwner, ...$paramsWithOwner);
            } else {
                $sqlNoOwner = "INSERT INTO kos (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = mysqli_prepare($conn, $sqlNoOwner);
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }
            }

            if ($stmt && mysqli_stmt_execute($stmt)) {
                $success = "Data kos \"$name\" untuk kampus \"$campusName\" berhasil ditambahkan dan langsung muncul di pencarian!";
                $_POST = [];
            } else {
                $error = "Gagal menyimpan data kos: " . mysqli_error($conn);
            }
        }
    }
}

$formCampuses = isset($_POST['campuses']) && is_array($_POST['campuses'])
    ? array_values(array_filter(array_map('strval', $_POST['campuses']), static fn($v) => trim($v) !== ''))
    : [];
if (empty($formCampuses)) {
    $formCampuses = [''];
}

$selElecSelect = (string)($_POST['electricity_select'] ?? 'Token Sendiri');
$selElecCustom = (string)($_POST['electricity_custom'] ?? '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftarkan Kos - KosFind</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        html, body {
            max-width: 100%;
            overflow-x: hidden;
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
    </style>
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen py-6 sm:py-10 px-3 sm:px-4">
    <div class="max-w-3xl mx-auto">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-5">
            <a href="index.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-teal-600 bg-white px-3.5 py-2 rounded-xl border border-slate-200 shadow-sm transition">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Katalog
            </a>
            <span class="text-[11px] sm:text-xs text-slate-500 bg-white px-3 py-1.5 rounded-xl border border-slate-200">
                Akun: <strong><?= htmlspecialchars($user['name']) ?></strong> (<?= htmlspecialchars($user['role']) ?>)
            </span>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-8">
            <h1 class="text-lg sm:text-xl font-extrabold text-slate-900">Daftarkan Kos Baru</h1>
            <p class="text-xs text-slate-500 mt-1 mb-5">Lengkapi informasi kamar kos, listrik, PDAM, keamanan, dan nama kampus agar mudah ditemukan mahasiswa.</p>

            <?php if ($success !== ''): ?>
                <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold px-4 py-3 rounded-xl">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-xl">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                <!-- UPLOAD FOTO UTAMA DARI GALERI -->
                <div>
                    <label class="font-bold text-slate-700 block mb-1.5">Foto Utama Kamar (Pilih dari Galeri)</label>
                    <label for="kosPhotoInput" class="flex flex-col items-center justify-center w-full border-2 border-dashed border-slate-300 hover:border-teal-500 bg-slate-50 hover:bg-teal-50/30 rounded-2xl p-4 cursor-pointer transition">
                        <img id="kosPhotoPreview" src="" alt="Preview Foto Kos" class="hidden w-full max-h-56 object-cover rounded-xl mb-3 border border-slate-200">
                        <div id="kosPhotoPlaceholder" class="text-center py-3">
                            <div class="w-11 h-11 rounded-xl bg-teal-100 text-teal-600 flex items-center justify-center mx-auto mb-2 text-lg">
                                <i class="fa-solid fa-image"></i>
                            </div>
                            <p class="font-bold text-slate-700">Klik untuk pilih foto dari galeri / folder</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Format JPG, PNG, atau WEBP (Maks. 3 MB)</p>
                        </div>
                        <span id="kosPhotoFileName" class="text-[11px] font-semibold text-teal-700 mt-1"></span>
                    </label>
                    <input id="kosPhotoInput" type="file" name="kos_photo" accept="image/jpeg,image/png,image/webp,image/*" class="hidden" onchange="previewKosPhoto(this)">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Nama Kos *</label>
                        <input type="text" name="name" required value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>" placeholder="Contoh: Kos Putri Melati" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <label class="font-bold text-slate-700">Nama Kampus Terdekat (Bisa lebih dari 1) *</label>
                            <button type="button" onclick="addCampusRow('campusInputsContainer')" class="inline-flex items-center gap-1 text-[11px] font-bold text-teal-700 bg-teal-50 hover:bg-teal-100 border border-teal-200 px-2.5 py-1 rounded-lg transition cursor-pointer">
                                <i class="fa-solid fa-plus"></i> Tambah Kampus
                            </button>
                        </div>
                        <div id="campusInputsContainer" class="space-y-2">
                            <?php foreach ($formCampuses as $idx => $cVal): ?>
                                <div class="campus-input-row flex items-center gap-2">
                                    <input
                                        type="text"
                                        name="campuses[]"
                                        <?= $idx === 0 ? 'required' : '' ?>
                                        value="<?= htmlspecialchars($cVal) ?>"
                                        placeholder="<?= $idx === 0 ? 'Ketik nama kampus (bisa pisah koma: ITB, Unpad)' : 'Nama kampus lainnya...' ?>"
                                        class="flex-1 px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none"
                                    >
                                    <button
                                        type="button"
                                        onclick="removeCampusRow(this, 'campusInputsContainer')"
                                        title="Hapus baris kampus"
                                        class="px-3 py-2.5 rounded-xl border border-slate-200 text-slate-500 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition cursor-pointer"
                                    >
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Bisa tambah banyak kolom kampus atau pisahkan dengan koma dalam satu kolom.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Tipe Penghuni *</label>
                        <?php $selType = (string)($_POST['type'] ?? 'Putri'); ?>
                        <select name="type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <option value="Putri" <?= $selType === 'Putri' ? 'selected' : '' ?>>Putri</option>
                            <option value="Putra" <?= $selType === 'Putra' ? 'selected' : '' ?>>Putra</option>
                            <option value="Campur" <?= $selType === 'Campur' ? 'selected' : '' ?>>Campur</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Harga per Bulan (Rp) *</label>
                        <input type="number" name="price" min="100000" step="50000" required value="<?= htmlspecialchars((string)($_POST['price'] ?? '')) ?>" placeholder="1200000" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Sisa Kamar Tersedia</label>
                        <input type="number" name="available_rooms" min="0" step="1" value="<?= htmlspecialchars((string)($_POST['available_rooms'] ?? '1')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Jarak ke Kampus *</label>
                        <input type="text" name="distance" required value="<?= htmlspecialchars((string)($_POST['distance'] ?? '')) ?>" placeholder="Contoh: 250m dari Kampus" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Alamat Lengkap *</label>
                        <input type="text" name="address" required value="<?= htmlspecialchars((string)($_POST['address'] ?? '')) ?>" placeholder="Jl. Ganesha No. 10" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Ukuran Kamar</label>
                        <input type="text" name="size" value="<?= htmlspecialchars((string)($_POST['size'] ?? '3 x 4 Meter')) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
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
                        <?php $selPdam = (string)($_POST['pdam'] ?? 'Sudah Termasuk Biaya Kos'); ?>
                        <select name="pdam" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <?php foreach ($pdamPresets as $pOpt): ?>
                                <option value="<?= htmlspecialchars($pOpt) ?>" <?= $selPdam === $pOpt ? 'selected' : '' ?>><?= htmlspecialchars($pOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Keamanan</label>
                        <?php $selSecurity = (string)($_POST['security'] ?? 'CCTV 24 Jam'); ?>
                        <select name="security" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <?php foreach ($securityPresets as $sOpt): ?>
                                <option value="<?= htmlspecialchars($sOpt) ?>" <?= $selSecurity === $sOpt ? 'selected' : '' ?>><?= htmlspecialchars($sOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">No. WhatsApp Pemilik</label>
                        <input type="text" name="owner_whatsapp" value="<?= htmlspecialchars((string)($_POST['owner_whatsapp'] ?? ($user['phone'] ?? '6281260007890'))) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Query Peta Google Maps</label>
                    <input type="text" name="map_query" value="<?= htmlspecialchars((string)($_POST['map_query'] ?? '')) ?>" placeholder="Contoh: Jalan Ganesha Bandung" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Fasilitas (pisahkan dengan koma)</label>
                    <input type="text" name="facilities" value="<?= htmlspecialchars((string)($_POST['facilities'] ?? '')) ?>" placeholder="Kasur, Lemari, AC, Wi-Fi, Kamar Mandi Dalam" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Deskripsi Hunian</label>
                    <textarea name="description" rows="3" placeholder="Ceritakan keunggulan kos, suasana sekitar, dan akses kampus..." class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none"><?= htmlspecialchars((string)($_POST['description'] ?? '')) ?></textarea>
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Peraturan Kos (1 baris = 1 peraturan)</label>
                    <textarea name="rules" rows="4" placeholder="Contoh:&#10;Tamu lawan jenis dilarang masuk ke dalam kamar.&#10;Jam malam pagar utama dikunci pukul 23:00 WIB.&#10;Menjaga kebersihan area umum & dapur bersama." class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none"><?= htmlspecialchars((string)($_POST['rules'] ?? '')) ?></textarea>
                </div>

                <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 px-4 rounded-xl shadow-sm transition">
                    <i class="fa-solid fa-plus-circle mr-1"></i> Simpan & Publikasikan Kos
                </button>
            </form>
        </div>
    </div>

    <script>
        function addCampusRow(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const row = document.createElement('div');
            row.className = 'campus-input-row flex items-center gap-2';

            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'campuses[]';
            input.placeholder = 'Nama kampus lainnya...';
            input.className = 'flex-1 px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none';

            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.title = 'Hapus baris kampus';
            delBtn.className = 'px-3 py-2.5 rounded-xl border border-slate-200 text-slate-500 hover:text-rose-600 hover:border-rose-200 hover:bg-rose-50 transition cursor-pointer';
            delBtn.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
            delBtn.onclick = function () {
                removeCampusRow(delBtn, containerId);
            };

            row.appendChild(input);
            row.appendChild(delBtn);
            container.appendChild(row);
            input.focus();
        }

        function removeCampusRow(btn, containerId) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const rows = container.querySelectorAll('.campus-input-row');
            const row = btn.closest('.campus-input-row');
            if (rows.length <= 1) {
                const inp = row ? row.querySelector('input') : null;
                if (inp) inp.value = '';
                return;
            }
            if (row) row.remove();
            const firstInput = container.querySelector('.campus-input-row input');
            if (firstInput) firstInput.required = true;
        }

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

        function previewKosPhoto(input) {
            const preview = document.getElementById('kosPhotoPreview');
            const placeholder = document.getElementById('kosPhotoPlaceholder');
            const fileNameEl = document.getElementById('kosPhotoFileName');

            if (input.files && input.files[0]) {
                const file = input.files[0];
                fileNameEl.innerText = 'File dipilih: ' + file.name;
                const reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                };
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
</html>