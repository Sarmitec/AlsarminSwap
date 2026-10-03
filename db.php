<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('[swap] DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('تعذر الاتصال بقاعدة البيانات. يرجى المحاولة لاحقًا أو مراجعة مسؤول النظام.');
    }

    try {
        $pdo->query('SELECT 1 FROM students LIMIT 1');
    } catch (PDOException $e) {
        error_log('[swap] DB schema check failed: ' . $e->getMessage());
        throw new RuntimeException('الجداول غير موجودة في قاعدة البيانات. يرجى استيراد ملف schema.sql أولًا.');
    }

    return $pdo;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function require_text(mixed $value, string $label): string
{
    $value = trim((string) $value);
    if ($value === '') throw new InvalidArgumentException('يرجى إدخال ' . $label . '.');
    return $value;
}

/**
 * تطبيع نص الفئة لأغراض المقارنة فقط (لا يُستخدم للتخزين ولا للعرض).
 * يعالج: المسافات الزائدة، الأرقام العربية/الفارسية مقابل اللاتينية،
 * الأصفار البادئة، واختلاف حالة الأحرف.
 */
function normalize_category(string $value): string
{
    // إزالة الأحرف غير المرئية الشائعة القادمة من نسخ/لصق إكسل:
    // NBSP (U+00A0), علامات الاتجاه RTL/LTR (U+200E/200F), الفراغ الصفري
    // (U+200B/FEFF)، والتطويل العربي (U+0640).
    $value = preg_replace('/[\x{00A0}\x{200B}\x{200E}\x{200F}\x{FEFF}\x{0640}]/u', '', $value) ?? $value;

    $value = trim($value);

    $easternDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $westernDigits = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $value = str_replace($easternDigits, $westernDigits, $value);

    // توحيد كل أنواع المسافات (بما فيها المسافات اليونيكود مثل NBSP إن
    // بقي أي أثر منها) إلى مسافة عادية واحدة، ثم إزالة أي مسافات زائدة
    // حول الرقم.
    $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value;
    $value = trim($value);

    if (preg_match('/^0+([0-9]+)$/', $value, $m)) {
        $value = $m[1];
    }

    // بعض قيم الفئات مخزّنة مع كلمة وصفية مثل "الفئة 2"، بينما الفئة
    // المطلوبة تُدخل كرقم مجرد "2". إن كانت القيمة تحتوي على رقم واحد
    // فقط، نعتمد الرقم وحده كأساس للمقارنة (يزيل أي بادئة/لاحقة نصية
    // مثل "الفئة"، "فئة"، "Category"...).
    if (preg_match('/^\D*([0-9]+)\D*$/u', $value, $m)) {
        $value = $m[1];
        if (preg_match('/^0+([0-9]+)$/', $value, $m2)) {
            $value = $m2[1];
        }
    }

    return mb_strtolower($value, 'UTF-8');
}

/**
 * يفحص كل الطلبات "قيد الانتظار" ويطابق كل زوج متعاكس (from/to معكوسان
 * بعد التطبيع) وفق أولوية التسجيل (الأقدم أولاً). يُنشئ سجل مطابقة،
 * يحدّث حالة الطلبين، يحدّث الفئة الحالية لكلا الطالبين، ويسجّل التاريخ.
 *
 * ملاحظة مهمة: هذه الدالة لا تفتح ولا تُغلق أي Transaction بنفسها.
 * على الكود المستدعي (caller) أن يكون قد فتح Transaction مسبقًا
 * (beginTransaction) وأن يقوم بعمل commit بعد استدعائها.
 *
 * @return int عدد المطابقات الجديدة التي تم إنشاؤها في هذا الاستدعاء.
 */
/**
 * يمسح بيانات مستخدم واحد فقط، بترتيب يحترم قيود المفاتيح الأجنبية.
 * لا يمسح سجل دفعات الاستيراد، ولا يفتح أو يغلق Transaction.
 */
function reset_user_data(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM category_history WHERE student_id IN (SELECT id FROM students WHERE user_id = ?)')->execute([$userId]);
    $pdo->prepare('DELETE FROM matches WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM swap_requests WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM students WHERE user_id = ?')->execute([$userId]);
}

function rematch_pending(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT * FROM swap_requests WHERE status='pending' AND user_id = ? ORDER BY id ASC FOR UPDATE");
    $stmt->execute([$userId]);
    $pending = $stmt->fetchAll();

    $consumed = [];
    $matchesMade = 0;

    foreach ($pending as $request) {
        if (isset($consumed[$request['id']])) continue;

        $normFrom = normalize_category($request['from_category']);
        $normTo   = normalize_category($request['to_category']);

        foreach ($pending as $candidate) {
            if ($candidate['id'] === $request['id']) continue;
            if ($candidate['id'] < $request['id']) continue; // تجنّب معالجة نفس الزوج مرتين
            if (isset($consumed[$candidate['id']])) continue;

            if (normalize_category($candidate['from_category']) === $normTo
                && normalize_category($candidate['to_category']) === $normFrom) {

                $match = $pdo->prepare('INSERT INTO matches(user_id, request_a_id, request_b_id) VALUES (?,?,?)');
                $match->execute([$userId, $request['id'], $candidate['id']]);
                $matchId = (int) $pdo->lastInsertId();

                $pdo->prepare("UPDATE swap_requests SET status='matched', matched_at=CURRENT_TIMESTAMP, match_id=? WHERE id IN (?,?) AND user_id = ?")
                    ->execute([$matchId, $request['id'], $candidate['id'], $userId]);

                $pdo->prepare('UPDATE students SET current_category=? WHERE id=? AND user_id=?')
                    ->execute([$request['to_category'], $request['student_id'], $userId]);
                $pdo->prepare('UPDATE students SET current_category=? WHERE id=? AND user_id=?')
                    ->execute([$candidate['to_category'], $candidate['student_id'], $userId]);

                $history = $pdo->prepare('INSERT INTO category_history(student_id,old_category,new_category,reason,request_id,match_id) VALUES (?,?,?,?,?,?)');
                $history->execute([$request['student_id'], $request['from_category'], $request['to_category'], 'match', $request['id'], $matchId]);
                $history->execute([$candidate['student_id'], $candidate['from_category'], $candidate['to_category'], 'match', $candidate['id'], $matchId]);

                $consumed[$request['id']] = true;
                $consumed[$candidate['id']] = true;
                $matchesMade++;
                break;
            }
        }
    }

    return $matchesMade;
}