<?php
require_once 'config/db.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

function hitungEstimasiSewa(int $hargaBulanan, int $durasiBulan): int
{
    $durasiValid = in_array($durasiBulan, [1, 3, 6, 12], true) ? $durasiBulan : 1;
    return max(0, $hargaBulanan) * $durasiValid;
}

function labelDurasiSewa(int $durasiBulan): string
{
    return $durasiBulan === 12 ? '12 Bulan (1 Tahun)' : ($durasiBulan . ' Bulan');
}

$user = currentUser();
$error = '';
$success = '';

$kosId = (int)($_GET['kos_id'] ?? $_POST['kos_id'] ?? 0);
$duration = (int)($_GET['duration'] ?? $_POST['duration_months'] ?? 1);
if (!in_array($duration, [1, 3, 6, 12], true)) {
    $duration = 1;
}

$kos = null;
if ($kosId > 0) {
    $stmtKos = mysqli_prepare($conn, "SELECT * FROM kos WHERE id = ? LIMIT 1");
    if ($stmtKos) {
        mysqli_stmt_bind_param($stmtKos, "i", $kosId);
        mysqli_stmt_execute($stmtKos);
        $kos = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtKos));
    }
}

if (!$kos) {
    header("Location: index.php");
    exit;
}

$monthlyPrice = (int)($kos['price'] ?? 0);
$estimatedTotal = hitungEstimasiSewa($monthlyPrice, $duration);
$checkinDate = date('Y-m-d');
$tenantNameInput = (string)($user['name'] ?? '');
$tenantPhoneInput = (string)($user['phone'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenantNameInput = trim((string)($_POST['tenant_name'] ?? ($user['name'] ?? '')));
    $tenantPhoneInput = trim((string)($_POST['tenant_phone'] ?? ($user['phone'] ?? '')));
    $tenantPhoneClean = preg_replace('/[^0-9]/', '', $tenantPhoneInput);

    // Durasi dikunci sesuai pilihan awal yang dikirim ke form
    $postedDuration = isset($_POST['duration_months']) ? (int)$_POST['duration_months'] : $duration;
    if (in_array($postedDuration, [1, 3, 6, 12], true)) {
        $duration = $postedDuration;
    }

    $rawCheckin = trim((string)($_POST['checkin_date'] ?? ''));
    $checkinDate = ($rawCheckin !== '') ? $rawCheckin : date('Y-m-d');
    $estimatedTotal = hitungEstimasiSewa($monthlyPrice, $duration);
    $userId = ($user && !empty($user['id'])) ? (int)$user['id'] : null;

    if ($tenantNameInput === '' || $tenantPhoneClean === '') {
        $error = "Nama pemesan dan nomor WhatsApp wajib diisi.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO bookings (kos_id, user_id, tenant_name, tenant_phone, duration_months, estimated_total, checkin_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        if ($stmt) {
            mysqli_stmt_bind_param(
                $stmt,
                "iissiis",
                $kosId,
                $userId,
                $tenantNameInput,
                $tenantPhoneClean,
                $duration,
                $estimatedTotal,
                $checkinDate
            );

            if (mysqli_stmt_execute($stmt)) {
                $newBookingId = (int)mysqli_insert_id($conn);
                $ownerIdVal = isset($kos['owner_id']) ? (int)$kos['owner_id'] : null;

                notifyNewBooking(
                    $conn,
                    $newBookingId,
                    $kosId,
                    (string)($kos['name'] ?? 'KosFind'),
                    $tenantNameInput,
                    $duration,
                    $estimatedTotal,
                    $ownerIdVal > 0 ? $ownerIdVal : null
                );

                $success = "Pesanan kos berhasil dicatat! Notifikasi telah dikirim ke admin dan pemilik kos.";
            } else {
                $error = "Gagal menyimpan pesanan: " . mysqli_error($conn);
            }
        } else {
            $error = "Gagal menyiapkan query pesanan: " . mysqli_error($conn);
        }
    }
}

$durationLabel = labelDurasiSewa($duration);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajukan Sewa - <?= htmlspecialchars((string)$kos['name']) ?></title>
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
<body class="bg-slate-50 font-sans text-slate-800 antialiased min-h-screen py-6 sm:py-8 px-3 sm:px-4">
    <div class="max-w-xl mx-auto">
        <a href="index.php" class="inline-flex items-center gap-2 text-xs font-bold text-slate-600 hover:text-teal-600 bg-white px-3.5 py-2 rounded-xl border border-slate-200 shadow-sm mb-4 sm:mb-5 transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Katalog
        </a>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 sm:p-8">
            <h1 class="text-lg sm:text-xl font-extrabold text-slate-900">Form Pemesanan Kos</h1>
            <p class="text-xs text-slate-500 mt-1 mb-5 leading-relaxed">
                Properti: <strong class="text-slate-800"><?= htmlspecialchars((string)$kos['name']) ?></strong> &bull;
                Tarif: <strong class="text-teal-700">Rp <?= number_format($monthlyPrice, 0, ',', '.') ?>/bulan</strong>
            </p>

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

            <form method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="kos_id" value="<?= $kosId ?>">
                <input type="hidden" name="duration_months" value="<?= $duration ?>">

                <div>
                    <label class="font-bold text-slate-700 block mb-1">Nama Lengkap Pemesan *</label>
                    <input type="text" name="tenant_name" required value="<?= htmlspecialchars($tenantNameInput) ?>" placeholder="Nama mahasiswa / penyewa" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>

                <div>
                    <label class="font-bold text-slate-700 block mb-1">No. WhatsApp Pemesan *</label>
                    <input type="text" name="tenant_phone" required value="<?= htmlspecialchars($tenantPhoneInput) ?>" placeholder="6281234567890" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Durasi Sewa</label>
                        <div class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 bg-slate-100 text-slate-700 font-semibold flex items-center justify-between cursor-not-allowed select-none" aria-readonly="true">
                            <span><?= htmlspecialchars($durationLabel) ?></span>
                        </div>
                    </div>
                    <div>
                        <label class="font-bold text-slate-700 block mb-1">Rencana Masuk</label>
                        <input type="date" name="checkin_date" value="<?= htmlspecialchars($checkinDate) ?>" class="w-full px-3.5 py-2.5 rounded-xl border border-slate-200 focus:ring-2 focus:ring-teal-500 focus:outline-none">
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-teal-50 border border-teal-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
                    <span class="font-bold text-teal-900">Estimasi Biaya (<?= $duration ?> bln):</span>
                    <span class="text-sm sm:text-base font-black text-teal-700">Rp <?= number_format($estimatedTotal, 0, ',', '.') ?></span>
                </div>

                <button type="submit" class="w-full bg-teal-600 hover:bg-teal-700 text-white font-bold py-3 px-4 rounded-xl shadow-sm transition">
                    <i class="fa-solid fa-check-circle mr-1"></i> Kirim Pesanan Kos
                </button>
            </form>
        </div>
    </div>
</body>
</html>