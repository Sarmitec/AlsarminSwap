<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf'] ?? '');
    if ($token !== '' && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $token)) {
        logout_user();
    }
}

header('Location: login.php');
exit;
