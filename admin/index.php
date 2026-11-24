<?php
require_once '../config/db.php';
require_once '../config/auth.php';

// Hanya admin yang boleh mengakses dashboard CMS ini
requireRole(['admin'], '../login.php');

$user = currentUser();
$query = "SELECT * FROM kos ORDER BY id DESC";
$result = mysqli_query($conn, $query);
$kosList = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Admin KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen py-10 px-4">
    <div class="max-w-5xl mx-auto bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <!-- HEADER -->
        <div class="flex flex-wrap items-center justify-between gap-4 pb-5 border-b border-slate-100">
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">CMS Admin - Data Kos</h1>
                <p class="text-xs text-slate-500 mt-1">
                    Jumlah data kos di database: <strong class="text-slate-800"><?= count($kosList) ?></strong>
                    &bull; Admin: <strong><?= htmlspecialchars($user['name']) ?></strong>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <a href="../index.php" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3.5 py-2 rounded-xl transition">
                    Kembali ke Katalog
                </a>
                <a href="../logout.php" onclick="return confirm('Keluar dari akun admin?');" class="bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 font-bold px-3.5 py-2 rounded-xl transition">
                    Logout
                </a>
            </div>
        </div>

        <!-- TABEL DATA KOS -->
        <div class="overflow-x-auto mt-6">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-slate-500 uppercase tracking-wider text-[11px]">
                        <th class="py-3 px-3">Nama Kos</th>
                        <th class="py-3 px-3">Tipe</th>
                        <th class="py-3 px-3">Harga</th>
                        <th class="py-3 px-3">Jarak</th>
                        <th class="py-3 px-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($kosList as $row): ?>
                        <?php $targetKosId = (int)($row['id'] ?? 0); ?>
                        <tr class="hover:bg-slate-50/80 transition">
                            <td class="py-3.5 px-3">
                                <?php if ($targetKosId > 0): ?>
                                    <a href="../index.php?open_kos=<?= $targetKosId ?>"
                                       title="Lihat di katalog kos"
                                       class="font-bold text-slate-900 hover:text-teal-600 underline decoration-teal-500/40 underline-offset-2 transition inline-flex items-center gap-1">
                                        <span><?= htmlspecialchars($row['name']) ?></span>
                                        <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-teal-600"></i>
                                    </a>
                                <?php else: ?>
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars($row['name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-3">
                                <span class="px-2.5 py-1 rounded-lg font-bold text-[11px] bg-teal-50 text-teal-700">
                                    <?= htmlspecialchars($row['type']) ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-3 font-semibold text-slate-700">
                                Rp <?= number_format((int)$row['price'], 0, ',', '.') ?>
                            </td>
                            <td class="py-3.5 px-3 text-slate-600">
                                <?= htmlspecialchars($row['distance']) ?>
                            </td>
                            <td class="py-3.5 px-3 text-right whitespace-nowrap space-x-1.5">
                                <a href="edit.php?id=<?= (int)$row['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 font-bold transition">
                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                </a>
                                <a href="hapus.php?id=<?= (int)$row['id'] ?>" onclick="return confirm('Yakin ingin menghapus kos <?= htmlspecialchars(addslashes($row['name'])) ?>?');" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 font-bold transition">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>