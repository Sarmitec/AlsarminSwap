<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

set_exception_handler(function (Throwable $e): void {
    error_log('[swap] Uncaught: ' . $e);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!doctype html><meta charset="utf-8"><p dir="rtl" style="font-family:sans-serif;padding:24px">حدث خطأ غير متوقع. يرجى المحاولة لاحقًا.</p>';
});

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function send_security_headers(): void
{
    static $sent = false;

    if ($sent || headers_sent()) {
        return;
    }

    $sent = true;

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

function start_secure_session(): void
{
    send_security_headers();

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    session_set_cookie_params($cookieParams);
    session_name('swap_sid');
    session_start();

    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 28800) {
        $_SESSION = [];
        session_destroy();
        session_set_cookie_params($cookieParams);
        session_name('swap_sid');
        session_start();
        session_regenerate_id(true);
    }

    $_SESSION['last_activity'] = time();
}

function current_user(): ?array
{
    static $userLoaded = false;
    static $user = null;

    if ($userLoaded) {
        return $user;
    }

    $userLoaded = true;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, full_name, email, role FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if ($user === null) {
        $_SESSION = [];
        return null;
    }

    return $user;
}

function require_login_page(): void
{
    if (current_user() === null) {
        header('Location: login.php');
        exit;
    }
}

function require_login_api(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'انتهت الجلسة. يرجى تسجيل الدخول.', 'auth' => true], 401);
    }

    return $user;
}

function require_admin_api(array $user): void
{
    if (($user['role'] ?? '') !== 'admin') {
        json_response(['error' => 'ليست لديك صلاحية لتنفيذ هذا الإجراء.'], 403);
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    if ($token === '' || !isset($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
        json_response(['error' => 'رمز الأمان غير صالح. أعد تحميل الصفحة.'], 419);
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $user['id']]);
}

function logout_user(): void
{
    $params = session_get_cookie_params();
    $_SESSION = [];
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => (bool) ($params['secure'] ?? false),
        'httponly' => (bool) ($params['httponly'] ?? true),
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
    session_destroy();
}

function normalize_email(string $email): string
{
    return mb_strtolower(trim($email), 'UTF-8');
}

function validate_registration(string $name, string $email, string $pass, string $pass2): array
{
    $errors = [];
    $name = trim($name);
    $email = normalize_email($email);

    $nameLength = mb_strlen($name, 'UTF-8');
    if ($nameLength < 2 || $nameLength > 255) {
        $errors[] = 'الاسم يجب أن يكون بين حرفين و255 حرفًا.';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
        $errors[] = 'يرجى إدخال بريد إلكتروني صحيح.';
    }
    if (strlen($pass) < 8 || strlen($pass) > 72) {
        $errors[] = 'كلمة المرور يجب أن تكون بين 8 و72 بايتًا.';
    } elseif (!preg_match('/[A-Za-z\x{0600}-\x{06FF}]/u', $pass) || !preg_match('/[0-9]/', $pass)) {
        $errors[] = 'كلمة المرور يجب أن تحتوي على حرف ورقم على الأقل.';
    }
    if ($pass !== $pass2) {
        $errors[] = 'تأكيد كلمة المرور غير مطابق.';
    }

    return $errors;
}

function too_many_attempts(PDO $pdo, string $email, string $ip): bool
{
    $window = 'attempted_at >= NOW() - INTERVAL 15 MINUTE';

    $pair = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip_address = ? AND $window");
    $pair->execute([$email, $ip]);
    if ((int) $pair->fetchColumn() >= 5) {
        return true;
    }

    $byEmail = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND $window");
    $byEmail->execute([$email]);
    if ((int) $byEmail->fetchColumn() >= 40) {
        return true;
    }

    $byIp = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND $window");
    $byIp->execute([$ip]);

    return (int) $byIp->fetchColumn() >= 20;
}

function record_failed_attempt(PDO $pdo, string $email, string $ip): void
{
    $pdo->prepare('INSERT INTO login_attempts(email, ip_address) VALUES (?, ?)')->execute([$email, $ip]);
}

function clear_attempts(PDO $pdo, string $email): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
