<?php
require_once '../config/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit;
}

$stmtGet = mysqli_prepare($conn, "SELECT * FROM kos WHERE id = ?");
mysqli_stmt_bind_param($stmtGet, "i", $id);
mysqli_stmt_execute($stmtGet);
$resGet = mysqli_stmt_get_result($stmtGet);
$kos = mysqli_fetch_assoc($resGet);

if (!$kos) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'Putri';
    $price = (int)($_POST['price'] ?? 0);
    $distance = trim($_POST['distance'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $rating = (float)($_POST['rating'] ?? 4.8);
    $size = trim($_POST['size'] ?? '');
    $electricity = trim($_POST['electricity'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $facilities = trim($_POST['facilities'] ?? '');
    $mapQuery = trim($_POST['map_query'] ?? '');
    $mainImage = trim($_POST['main_image'] ?? '');
    $ownerWhatsapp = preg_replace('/[^0-9]/', '', $_POST['owner_whatsapp'] ?? '6281260007890');

    if (!in_array($type, ['Putri', 'Putra', 'Campur'], true)) {
        $type = 'Putri';
    }

    if ($rating < 1 || $rating > 5) {
        $rating = 4.8;
    }

    if ($name === '' || $price <= 0 || $distance === '' || $address === '') {
        $error = "Nama kos, tarif bulanan, jarak, dan alamat wajib diisi dengan benar.";
    } else {
        if ($mainImage === '') {
            $mainImage = 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80';
        }
        if ($mapQuery === '') {
            $mapQuery = $address;
        }
        if ($ownerWhatsapp === '') {
            $ownerWhatsapp = '6281260007890';
        }

        $stmtUpdate = mysqli_prepare(
            $conn,
            "UPDATE kos
             SET name = ?, type = ?, price = ?, distance = ?, address = ?, rating = ?,
                 size = ?, electricity = ?, description = ?, facilities = ?,
                 map_query = ?, main_image = ?, owner_whatsapp = ?
             WHERE id = ?"
        );
        mysqli_stmt_bind_param(
            $stmtUpdate,
            "ssisdssssssssi",
            $name, $type, $price, $distance, $address, $rating,
            $size, $electricity, $description, $facilities,
            $mapQuery, $mainImage, $ownerWhatsapp, $id
        );

        if (mysqli_stmt_execute($stmtUpdate)) {
            header("Location: index.php?status=updated");
            exit;
        } else {
            $error = "Gagal memperbarui data: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Data Kos - CMS KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen py-8 px-4">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="index.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-teal-600 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Kos
            </a>
            <span class="text-xs font-bold text-amber-600 uppercase tracking-wider">Update Data Kos #<?= (int)$kos['id'] ?></span>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
            <h1 class="text-xl font-extrabold text-slate-900 mb-1">Edit Data Kos</h1>
            <p class="text-xs text-slate-500 mb-6">Perbarui informasi kamar, harga, atau fasilitas kos.</p>

            <?php if ($error !== ''): ?>
                <div class="mb-5 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-xl">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Nama Kos *</label>
                        <input type="text" name="name" required value="<?= htmlspecialchars($kos['name']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Tipe Gender *</label>
                        <select name="type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <option value="Putri" <?= $kos['type'] === 'Putri' ? 'selected' : '' ?>>Putri</option>
                            <option value="Putra" <?= $kos['type'] === 'Putra' ? 'selected' : '' ?>>Putra</option>
                            <option value="Campur" <?= $kos['type'] === 'Campur' ? 'selected' : '' ?>>Campur</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Tarif / Bulan (Rp) *</label>
                        <input type="number" name="price" required min="100000" step="50000" value="<?= (int)$kos['price'] ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Ukuran Kamar</label>
                        <input type="text" name="size" value="<?= htmlspecialchars($kos['size']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Rating (1.0 - 5.0)</label>
                        <input type="number" name="rating" step="0.1" min="1" max="5" value="<?= htmlspecialchars($kos['rating']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Jarak ke Kampus USU *</label>
                        <input type="text" name="distance" required value="<?= htmlspecialchars($kos['distance']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Info Listrik</label>
                        <input type="text" name="electricity" value="<?= htmlspecialchars($kos['electricity']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Alamat Lengkap *</label>
                        <input type="text" name="address" required value="<?= htmlspecialchars($kos['address']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Query Peta Google Maps</label>
                        <input type="text" name="map_query" value="<?= htmlspecialchars($kos['map_query']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">URL Foto Utama</label>
                        <input type="text" name="main_image" value="<?= htmlspecialchars($kos['main_image']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">No. WhatsApp Pemilik</label>
                        <input type="text" name="owner_whatsapp" value="<?= htmlspecialchars($kos['owner_whatsapp']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Fasilitas (pisahkan dengan koma)</label>
                    <input type="text" name="facilities" value="<?= htmlspecialchars($kos['facilities']) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Deskripsi Hunian</label>
                    <textarea name="description" rows="3" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none"><?= htmlspecialchars($kos['description']) ?></textarea>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <a href="index.php" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition">Batal</a>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-teal-600 hover:bg-teal-700 text-white font-bold shadow-sm transition">
                        <i class="fa-solid fa-check mr-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>