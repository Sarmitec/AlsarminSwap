<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
start_secure_session();

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');

    if ($csrf === '' || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $csrf)) {
        $errors[] = 'رمز الأمان غير صالح. أعد تحميل الصفحة.';
    } else {
        $errors = validate_registration($name, $email, $password, $passwordConfirmation);
    }

    if (!$errors) {
        $pdo = db();
        try {
            $insert = $pdo->prepare("INSERT INTO users(full_name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();

            login_user(['id' => $userId]);
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'هذا البريد مسجّل مسبقًا.';
            } else {
                throw $e;
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
    <title>إنشاء حساب - Alsarmini Swap</title>
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
        <h2>إنشاء حساب جديد</h2>
        <?php if ($errors): ?>
            <div class="notice error">
                <?php foreach ($errors as $error): ?>
                    <div><?= page_escape($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form class="auth-form" method="post">
            <input type="hidden" name="csrf" value="<?= page_escape(csrf_token()) ?>">
            <label>الاسم الكامل<input name="full_name" value="<?= page_escape($name) ?>" required maxlength="255"></label>
            <label>البريد الإلكتروني<input type="email" name="email" value="<?= page_escape($email) ?>" required maxlength="191"></label>
            <label>كلمة المرور<input type="password" name="password" required minlength="8" maxlength="72"></label>
            <label>تأكيد كلمة المرور<input type="password" name="password_confirmation" required minlength="8" maxlength="72"></label>
            <button class="btn-primary" type="submit">إنشاء الحساب</button>
        </form>
        <div class="auth-links">لديك حساب؟ <a href="login.php">سجّل الدخول</a></div>
    </section>
</main>
<script src="auth-ui.js"></script>
</body>
</html>
