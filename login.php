<?php
require_once 'config/db.php';
require_once 'config/auth.php';

if (isLoggedIn()) {
    if ($_SESSION['user']['role'] === 'admin') {
        header("Location: admin/index.php");
        exit;
    }
    header("Location: index.php");
    exit;
}

$error = '';
$registered = isset($_GET['registered']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "Email dan password wajib diisi.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id, name, email, password, role, phone FROM users WHERE email = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($res);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'phone' => $user['phone']
            ];

            if ($user['role'] === 'admin') {
                header("Location: admin/index.php");
            } else {
                header("Location: index.php");
            }
            exit;
        } else {
            $error = "Email atau password tidak sesuai.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>login - KosFind</title>
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
            <a href="index.php" class="text-xs font-bold text-slate-500 hover:text-teal-600">
                <i class="fa-solid fa-arrow-left mr-1"></i> Beranda
            </a>
        </div>

        <h1 class="text-xl font-extrabold text-slate-900">Masuk ke Akun</h1>
        <p class="text-xs text-slate-500 mt-1 mb-6">Login sebagai Admin, Pemilik Kos, atau Pencari Kos.</p>

        <?php if ($registered): ?>
            <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold px-4 py-3 rounded-xl">
                Registrasi berhasil! Silakan masuk dengan akun kamu.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold px-4 py-3 rounded-xl">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4 text-xs">
            <div>
                <label class="font-bold text-slate-700 block mb-1">Email</label>
                <input type="email" name="email" required placeholder="nama@email.com" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <div>
                <label class="font-bold text-slate-700 block mb-1">Password</label>
                <input type="password" name="password" required placeholder="••••••••" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
            </div>

            <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 rounded-xl shadow-sm transition">
                <i class="fa-solid fa-right-to-bracket mr-1"></i> Masuk Sekarang
            </button>
        </form>

        <p class="text-center text-xs text-slate-500 mt-5">
            Belum punya akun? <a href="register.php" class="text-teal-600 font-bold hover:underline">Daftar di sini</a>
        </p>
    </div>
</body>
</html>