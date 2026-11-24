<?php
require_once 'config/db.php';
require_once 'config/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $password = $_POST['password'] ?? '';

    if (!in_array($role, ['user', 'pemilik'], true)) {
        $role = 'user';
    }

    if ($name === '' || $email === '' || strlen($password) < 5) {
        $error = "Nama, email wajib diisi, dan password minimal 5 karakter.";
    } else {
        $check = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($check, "s", $email);
        mysqli_stmt_execute($check);
        $exists = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

        if ($exists) {
            $error = "Email sudah terdaftar. Silakan gunakan email lain atau login.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $hashed, $role, $phone);

            if (mysqli_stmt_execute($stmt)) {
                header("Location: login.php?registered=1");
                exit;
            } else {
                $error = "Gagal mendaftar: " . mysqli_error($conn);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - KosFind USU</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 font-sans text-slate-800 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8">
        <div class="flex items-center justify-between mb-6">
            <a href="index.php" class="flex items-center space-x-2.5">
                <div class="bg-teal-600 text-white p-2 rounded-xl font-bold text-lg w-9 h-9 flex items-center justify-center">
                    <i class="fa-solid fa-house-chimney"></i>
                </div>
                <span class="text-lg font-black text-slate-900">Kos<span class="text-teal-600">Find</span></span>
            </a>
            <a href="login.php" class="text-xs font-bold text-teal-600 hover:underline">Sudah punya akun?</a>
        </div>

        <h1 class="text-xl font-extrabold text-slate-900">Buat Akun Baru</h1>
        <p class="text-xs text-slate-500 mt-1 mb-6">Pilih peran sebagai Pencari Kos (Mahasiswa) atau Pemilik Kos.</p>

        <?php if ($error !== ''): ?>
            <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-xl">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4 text-xs">
            <div>
                <label class="font-bold text-slate-700 block mb-1">Daftar Sebagai *</label>
                <select name="role" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-white focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    <option value="user">Mahasiswa / Pencari Kos</option>
                    <option value="pemilik">Pemilik Kos (Ingin Mendaftarkan Kos)</option>
                </select>
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Nama Lengkap *</label>
                <input type="text" name="name" required placeholder="Nama lengkap kamu" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Email *</label>
                <input type="email" name="email" required placeholder="nama@email.com" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">No. WhatsApp (Format 628...)</label>
                <input type="text" name="phone" placeholder="6281234567890" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Password *</label>
                <input type="password" name="password" required minlength="5" placeholder="Minimal 5 karakter" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-xl shadow-sm transition">
                <i class="fa-solid fa-user-plus mr-1"></i> Daftar Sekarang
            </button>
        </form>
    </div>
</body>
</html>