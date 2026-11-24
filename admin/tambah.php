<?php
require_once '../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $type =$_POST['type'] ?? 'Putri';
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
    $ownerWhatsapp = preg_replace('/[^0-9]/', '',$_POST['owner_whatsapp'] ?? '6281260007890');

    if (!in_array($type, ['Putri', 'Putra', 'Campur'], true)) {$type = 'Putri';
    }

    if ($rating < 1 || $rating > 5) {$rating = 4.8;
    }

    if ($name === '' || $price <= 0 \vert{}\vert{}$distance === '' || $address === '') {$error = "Nama kos, tarif bulanan, jarak, dan alamat wajib diisi dengan benar.";
    } else {
        if ($mainImage === '') {$mainImage = 'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80';
        }
        if ($mapQuery === '') {
            $mapQuery =$address;
        }
        if ($ownerWhatsapp === '') {$ownerWhatsapp = '6281260007890';
        }

        $stmt = mysqli_prepare($conn,
            "INSERT INTO kos (name, type, price, distance, address, rating, size, electricity, description, facilities, map_query, main_image, owner_whatsapp)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "ssisdssssssss",
            $name, $type,$price, $distance,$address, $rating,$size, $electricity,$description, $facilities,$mapQuery, $mainImage,$ownerWhatsapp
        );

        if (mysqli_stmt_execute($stmt)) {
            header("Location: index.php?status=created");
            exit;
        } else {
            $error = "Gagal menyimpan data: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Data Kos - CMS KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen py-8 px-4">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="index.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-teal-600 bg-white px-4 py-2 rounded-xl border border-slate-200 shadow-sm">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Kos
            </a>
            <span class="text-xs font-bold text-teal-600 uppercase tracking-wider">Create Data Kos</span>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
            <h1 class="text-xl font-extrabold text-slate-900 mb-1">Tambah Kos Baru</h1>
            <p class="text-xs text-slate-500 mb-6">Data yang disimpan akan langsung muncul di katalog KosFind USU[cite: 1].</p>

            <?php if ($error !== ''): ?>
                <div class="mb-5 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-xl">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4 text-xs">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Nama Kos *</label>
                        <input type="text" name="name" required placeholder="Contoh: Kos Melati Padang Bulan" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Tipe Gender *</label>
                        <select name="type" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <option value="Putri">Putri</option>
                            <option value="Putra">Putra</option>
                            <option value="Campur">Campur</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Tarif / Bulan (Rp) *</label>
                        <input type="number" name="price" required min="100000" step="50000" placeholder="1500000" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Ukuran Kamar</label>
                        <input type="text" name="size" placeholder="3 x 4 Meter" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Rating (1.0 - 5.0)</label>
                        <input type="number" name="rating" step="0.1" min="1" max="5" value="4.8" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Jarak ke Kampus USU *</label>
                        <input type="text" name="distance" required placeholder="250 meter dari Pintu 1 USU" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus