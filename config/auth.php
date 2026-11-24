<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function requireLogin(string $redirectTo = 'login.php'): void
{
    if (!isLoggedIn()) {
        header("Location: $redirectTo");
        exit;
    }
}

function requireRole(array $allowedRoles, string $redirectTo = 'login.php'): void
{
    if (!isLoggedIn() || !in_array($_SESSION['user']['role'], $allowedRoles, true)) {
        header("Location: $redirectTo");
        exit;
    }
}