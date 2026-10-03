<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
start_secure_session();

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');

    if ($csrf === '' || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
        $error = 'رمز الأمان غير صالح. أعد تحميل الصفحة.';
    } else {
        if (mb_strlen($email, 'UTF-8') > 191 || strlen($password) > 1024) {
            $error = 'البريد أو كلمة المرور غير صحيحة.';
        } else {
            $pdo = db();
            $ip = client_ip();

            if (too_many_attempts($pdo, $email, $ip)) {
                $error = 'محاولات كثيرة. حاول مرة أخرى بعد 15 دقيقة.';
            } else {
                $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = ?');
                $stmt->execute([$email]);
                $user = $stmt->fetch() ?: null;
                $dummyHash = '$2y$10$Y3lzZeumzVGqGFCLcPdJOelMb3ZjM50YFddqTV6F2ajRmSzc0B0te';
                $valid = password_verify($password, $user['password_hash'] ?? $dummyHash);

                if (!$user || !$valid) {
                    record_failed_attempt($pdo, $email, $ip);
                    $error = 'البريد أو كلمة المرور غير صحيحة.';
                } else {
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
                    }
                    clear_attempts($pdo, $email);
                    login_user($user);
                    header('Location: index.php');
                    exit;
                }
            }

            if (random_int(1, 100) === 1) {
                $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
                $cleanup->execute();
            }
        }
    }
}

function page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول - Alsarmini Swap</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header class="header">
    <div class="brand"><span>⇄</span> Alsarmini Swap</div>
</header>
<main class="container">
    <section class="card auth-card">
        <h2>تسجيل الدخول</h2>
        <?php if ($error !== ''): ?><div class="notice error"><?= page_escape($error) ?></div><?php endif; ?>
        <form class="auth-form" method="post">
            <input type="hidden" name="csrf" value="<?= page_escape(csrf_token()) ?>">
            <label>البريد الإلكتروني<input type="email" name="email" value="<?= page_escape($email) ?>" required maxlength="191"></label>
            <label>كلمة المرور<input type="password" name="password" required maxlength="72"></label>
            <button class="btn-primary" type="submit">تسجيل الدخول</button>
        </form>
        <div class="auth-links">ليس لديك حساب؟ <a href="register.php">أنشئ حسابًا</a></div>
    </section>
</main>
<script src="auth-ui.js"></script>
</body>
</html>
