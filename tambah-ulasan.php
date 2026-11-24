<?php
require_once 'config/db.php';
require_once 'config/auth.php';

$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

$kosId = (int)($_POST['kos_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 5);
$comment = trim($_POST['comment'] ?? '');
$reviewerName = trim($_POST['reviewer_name'] ?? ($user['name'] ?? 'Mahasiswa USU'));
$userId = $user ? (int)$user['id'] : null;

if ($rating < 1) {
    $rating = 1;
} elseif ($rating > 5) {
    $rating = 5;
}

if ($kosId > 0 && $comment !== '' && $reviewerName !== '') {
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO reviews (kos_id, user_id, reviewer_name, rating, comment)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iisis", $kosId, $userId, $reviewerName, $rating, $comment);
        mysqli_stmt_execute($stmt);

        // Hitung ulang rata-rata rating untuk kos ini
        $stmtAvg = mysqli_prepare(
            $conn,
            "SELECT ROUND(AVG(rating), 1) AS avg_rating FROM reviews WHERE kos_id = ?"
        );
        if ($stmtAvg) {
            mysqli_stmt_bind_param($stmtAvg, "i", $kosId);
            mysqli_stmt_execute($stmtAvg);
            $avgRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtAvg));
            if ($avgRow && $avgRow['avg_rating'] !== null) {
                $newRating = (float)$avgRow['avg_rating'];
                $stmtUpd = mysqli_prepare($conn, "UPDATE kos SET rating = ? WHERE id = ?");
                if ($stmtUpd) {
                    mysqli_stmt_bind_param($stmtUpd, "di", $newRating, $kosId);
                    mysqli_stmt_execute($stmtUpd);
                }
            }
        }
    }
}

header("Location: index.php?open_kos=" . $kosId . "#ulasan-kos");
exit;