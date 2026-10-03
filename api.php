<?php
declare(strict_types=1);


require_once __DIR__ . '/db.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/auth.php';
start_secure_session();

$pdo = null;

try {
    $pdo = db();
    $action = $_GET['action'] ?? 'state';
    $authUser = require_login_api();
    $uid = (int) $authUser['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
    }

    if ($action === 'state') {
        $stats = [
            'students' => (int) (function () use ($pdo, $uid): int { $stmt = $pdo->prepare('SELECT COUNT(*) FROM students WHERE user_id = ?'); $stmt->execute([$uid]); return (int) $stmt->fetchColumn(); })(),
            'pending' => (int) (function () use ($pdo, $uid): int { $stmt = $pdo->prepare("SELECT COUNT(*) FROM swap_requests WHERE status='pending' AND user_id = ?"); $stmt->execute([$uid]); return (int) $stmt->fetchColumn(); })(),
            'matched' => (int) (function () use ($pdo, $uid): int { $stmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE status='active' AND user_id = ?"); $stmt->execute([$uid]); return (int) $stmt->fetchColumn(); })(),
        ];

        $stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$uid]);
        $students = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT r.*, s.university_id, s.full_name FROM swap_requests r JOIN students s ON s.id = r.student_id AND s.user_id = ? WHERE r.user_id = ? ORDER BY r.id DESC');
        $stmt->execute([$uid, $uid]);
        $requests = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT m.*,
                a.from_category AS a_from, a.to_category AS a_to,
                sa.full_name AS a_name, sa.university_id AS a_uid,
                b.from_category AS b_from, b.to_category AS b_to,
                sb.full_name AS b_name, sb.university_id AS b_uid
            FROM matches m
            JOIN swap_requests a ON a.id = m.request_a_id
            JOIN swap_requests b ON b.id = m.request_b_id
            JOIN students sa ON sa.id = a.student_id AND sa.user_id = ?
            JOIN students sb ON sb.id = b.student_id AND sb.user_id = ?
            WHERE m.status = 'active' AND m.user_id = ? AND a.user_id = ? AND b.user_id = ?
            ORDER BY m.id DESC
        ");
        $stmt->execute([$uid, $uid, $uid, $uid, $uid]);
        $matches = $stmt->fetchAll();

        foreach ($students as &$student) {
            $student['extra_fields'] = json_decode($student['extra_fields'], true) ?: [];
        }

        json_response(compact('stats', 'students', 'requests', 'matches'));
    }

    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['file'])) {
            throw new RuntimeException('يرجى اختيار ملف Excel صحيح.');
        }

        $uploadError = (int) $_FILES['file']['error'];

        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('حجم الملف يتجاوز الحد المسموح به في إعدادات الخادم.');
        }

        if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
            throw new RuntimeException('يرجى اختيار ملف Excel صحيح.');
        }

        $originalName = (string) $_FILES['file']['name'];
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('صيغة الملف غير مدعومة. يُقبل ملفات .xlsx أو .csv فقط.');
        }

        $maxUploadBytes = 10 * 1024 * 1024;

        if ((int) $_FILES['file']['size'] > $maxUploadBytes) {
            throw new RuntimeException('حجم الملف كبير جدًا. الحد الأقصى المسموح به 10 ميجابايت.');
        }

        $parsed = read_import_file($_FILES['file']['tmp_name'], $extension);

        $mode = ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';

        $pdo->beginTransaction();

        if ($mode === 'replace') {
            reset_user_data($pdo, $uid);
        }

        $updated = 0;
        $inserted = 0;

        $find = $pdo->prepare('SELECT id FROM students WHERE user_id = ? AND university_id = ?');
        $insert = $pdo->prepare('INSERT INTO students(user_id, university_id, full_name, original_category, current_category, extra_fields) VALUES (?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE students SET full_name = ?, extra_fields = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND university_id = ?');

        foreach ($parsed['rows'] as $row) {
            $exists = false;

            if ($mode === 'merge') {
                $find->execute([$uid, $row['university_id']]);
                $exists = $find->fetchColumn();
            }

            $extra = json_encode($row['extra_fields'], JSON_UNESCAPED_UNICODE);

            if ($exists) {
                $update->execute([$row['full_name'], $extra, $uid, $row['university_id']]);
                $updated++;
            } else {
                $insert->execute([$uid, $row['university_id'], $row['full_name'], $row['category'], $row['category'], $extra]);
                $inserted++;
            }
        }

        $pdo->commit();

        try {
            $pdo->prepare('INSERT INTO import_batches(user_id, file_name, inserted_count, updated_count, error_count, errors_json) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$uid, $_FILES['file']['name'], $inserted, $updated, count($parsed['errors']), json_encode($parsed['errors'], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $ignored) {
        }

        $message = $mode === 'replace'
            ? "تم استبدال البيانات بالكامل: {$inserted} طالب مستورد من الملف الجديد."
            : "تم استيراد {$inserted} طالب وتحديث {$updated} طالب مع الحفاظ على الفئات الحالية.";

        json_response([
            'message' => $message,
            'errors' => $parsed['errors'],
        ]);
    }

    if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->beginTransaction();
        reset_user_data($pdo, $uid);
        $pdo->commit();

        json_response(['message' => 'تم مسح كل بياناتك (الطلاب والطلبات والمطابقات) بنجاح.']);
    }

    if ($action === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = input_json();

        $studentId = (int) ($data['student_id'] ?? 0);
        $requestMode = (($data['mode'] ?? 'auto') === 'direct') ? 'direct' : 'auto';

        if ($requestMode === 'direct') {
            $partnerId = (int) ($data['partner_id'] ?? 0);

            if ($studentId <= 0 || $partnerId <= 0) {
                throw new InvalidArgumentException('يرجى اختيار الطالب والطالب البديل.');
            }

            if ($studentId === $partnerId) {
                throw new InvalidArgumentException('لا يمكن اختيار الطالب نفسه بديلًا لنفسه.');
            }

            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT * FROM students WHERE user_id = ? AND id IN (?, ?) ORDER BY id ASC FOR UPDATE');
            $lock->execute([$uid, $studentId, $partnerId]);

            $rows = [];
            foreach ($lock->fetchAll() as $row) {
                $rows[(int) $row['id']] = $row;
            }

            if (!isset($rows[$studentId]) || !isset($rows[$partnerId])) {
                throw new InvalidArgumentException('الطالب أو البديل غير موجود.');
            }

            $a = $rows[$studentId];
            $b = $rows[$partnerId];

            if (normalize_category((string) $a['current_category']) === normalize_category((string) $b['current_category'])) {
                throw new InvalidArgumentException('الطالبان في الفئة نفسها، لا حاجة للتبديل.');
            }

            $busy = $pdo->prepare("SELECT COUNT(*) FROM swap_requests WHERE user_id = ? AND status = 'pending' AND student_id IN (?, ?)");
            $busy->execute([$uid, $studentId, $partnerId]);

            if ((int) $busy->fetchColumn() > 0) {
                throw new InvalidArgumentException('لدى أحد الطالبين طلب قائم بالفعل. ألغِ الطلب القديم أولاً.');
            }

            $insReq = $pdo->prepare("INSERT INTO swap_requests(user_id, student_id, from_category, to_category, status, matched_at) VALUES (?, ?, ?, ?, 'matched', CURRENT_TIMESTAMP)");
            $insReq->execute([$uid, $studentId, $a['current_category'], $b['current_category']]);
            $reqA = (int) $pdo->lastInsertId();
            $insReq->execute([$uid, $partnerId, $b['current_category'], $a['current_category']]);
            $reqB = (int) $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO matches(user_id, request_a_id, request_b_id, match_type) VALUES (?, ?, ?, 'direct')")
                ->execute([$uid, $reqA, $reqB]);
            $matchId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE swap_requests SET match_id = ? WHERE id IN (?, ?) AND user_id = ?')
                ->execute([$matchId, $reqA, $reqB, $uid]);

            $setCategory = $pdo->prepare('UPDATE students SET current_category = ? WHERE id = ? AND user_id = ?');
            $setCategory->execute([$b['current_category'], $studentId, $uid]);
            $setCategory->execute([$a['current_category'], $partnerId, $uid]);

            $history = $pdo->prepare('INSERT INTO category_history(student_id, old_category, new_category, reason, request_id, match_id) VALUES (?, ?, ?, ?, ?, ?)');
            $history->execute([$studentId, $a['current_category'], $b['current_category'], 'match', $reqA, $matchId]);
            $history->execute([$partnerId, $b['current_category'], $a['current_category'], 'match', $reqB, $matchId]);

            $pdo->commit();

            json_response(['message' => 'تم التبديل المباشر بنجاح بين الطالبين.', 'matches_made' => 1]);
        }

        $to = require_text($data['to_category'] ?? '', 'الفئة المطلوبة');

        if (mb_strlen($to, 'UTF-8') > 100) {
            throw new InvalidArgumentException('الفئة المطلوبة طويلة جدًا (الحد الأقصى 100 حرف).');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$studentId, $uid]);
        $student = $stmt->fetch();

        if (!$student) {
            throw new InvalidArgumentException('الطالب غير موجود.');
        }

        if (normalize_category((string) $student['current_category']) === normalize_category($to)) {
            throw new InvalidArgumentException('الفئة المطلوبة مطابقة للفئة الحالية.');
        }

        $pending = $pdo->prepare("SELECT id FROM swap_requests WHERE student_id = ? AND status = 'pending' AND user_id = ?");
        $pending->execute([$studentId, $uid]);

        if ($pending->fetchColumn()) {
            throw new InvalidArgumentException('لدى الطالب طلب قائم بالفعل. ألغِ الطلب القديم أولاً.');
        }

        $insert = $pdo->prepare('INSERT INTO swap_requests(user_id, student_id, from_category, to_category) VALUES (?, ?, ?, ?)');
        $insert->execute([$uid, $studentId, $student['current_category'], $to]);

        $matchesMade = rematch_pending($pdo, $uid);

        $message = $matchesMade > 0
            ? 'تم تسجيل الطلب وإتمام المطابقة تلقائيًا.'
            : 'تم تسجيل الطلب في قائمة الانتظار.';

        $pdo->commit();

        json_response(['message' => $message, 'matches_made' => $matchesMade]);
    }

    if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) (input_json()['id'] ?? 0);

        $stmt = $pdo->prepare("UPDATE swap_requests SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->execute([$id, $uid]);

        if (!$stmt->rowCount()) {
            throw new InvalidArgumentException('لا يمكن إلغاء هذا الطلب.');
        }

        json_response(['message' => 'تم إلغاء الطلب بنجاح.']);
    }

    if ($action === 'undo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) (input_json()['id'] ?? 0);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT m.*,
                a.student_id AS a_student, a.from_category AS a_from, a.to_category AS a_to_old,
                b.student_id AS b_student, b.from_category AS b_from, b.to_category AS b_to_old
            FROM matches m
            JOIN swap_requests a ON a.id = m.request_a_id AND a.user_id = ?
            JOIN swap_requests b ON b.id = m.request_b_id AND b.user_id = ?
            WHERE m.id = ? AND m.user_id = ? AND m.status = 'active'
            FOR UPDATE
        ");
        $stmt->execute([$uid, $uid, $id, $uid]);
        $match = $stmt->fetch();

        if (!$match) {
            throw new InvalidArgumentException('المطابقة غير موجودة أو تم إلغاؤها.');
        }

        $lock = $pdo->prepare('SELECT id, current_category FROM students WHERE user_id = ? AND id IN (?, ?) ORDER BY id ASC FOR UPDATE');
        $lock->execute([$uid, $match['a_student'], $match['b_student']]);

        $currentCategories = [];
        foreach ($lock->fetchAll() as $row) {
            $currentCategories[(int) $row['id']] = (string) $row['current_category'];
        }

        $aNow = $currentCategories[(int) $match['a_student']] ?? null;
        $bNow = $currentCategories[(int) $match['b_student']] ?? null;

        if ($aNow === null || $bNow === null
            || normalize_category($aNow) !== normalize_category((string) $match['a_to_old'])
            || normalize_category($bNow) !== normalize_category((string) $match['b_to_old'])) {
            throw new InvalidArgumentException('لا يمكن إلغاء هذه المطابقة لأن فئة أحد الطالبين تغيّرت بعد إتمامها. ألغِ المطابقات الأحدث الخاصة بهما أولًا.');
        }

        $busy = $pdo->prepare("SELECT COUNT(*) FROM swap_requests WHERE user_id = ? AND status = 'pending' AND student_id IN (?, ?)");
        $busy->execute([$uid, $match['a_student'], $match['b_student']]);

        if ((int) $busy->fetchColumn() > 0) {
            throw new InvalidArgumentException('لدى أحد الطالبين طلب قائم بالفعل. ألغِ الطلب القائم أولًا ثم أعد المحاولة.');
        }

        $isDirect = (($match['match_type'] ?? 'auto') === 'direct');

        $pdo->prepare("UPDATE matches SET status = 'undone' WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        if ($isDirect) {
            $pdo->prepare("UPDATE swap_requests SET status = 'cancelled', matched_at = NULL, match_id = NULL WHERE match_id = ? AND user_id = ?")->execute([$id, $uid]);
        } else {
            $pdo->prepare("UPDATE swap_requests SET status = 'pending', matched_at = NULL, match_id = NULL WHERE match_id = ? AND user_id = ?")->execute([$id, $uid]);
        }

        $pdo->prepare('UPDATE students SET current_category = ? WHERE id = ? AND user_id = ?')->execute([$match['a_from'], $match['a_student'], $uid]);
        $pdo->prepare('UPDATE students SET current_category = ? WHERE id = ? AND user_id = ?')->execute([$match['b_from'], $match['b_student'], $uid]);

        $history = $pdo->prepare('INSERT INTO category_history(student_id, old_category, new_category, reason, request_id, match_id) VALUES (?, ?, ?, ?, ?, ?)');
        $history->execute([$match['a_student'], $match['a_to_old'], $match['a_from'], 'undo', $match['request_a_id'], $id]);
        $history->execute([$match['b_student'], $match['b_to_old'], $match['b_from'], 'undo', $match['request_b_id'], $id]);

        $pdo->commit();

        json_response(['message' => $isDirect
            ? 'تم إلغاء التبديل المباشر وإعادة فئتي الطالبين، وأُلغي الطلبان نهائيًا.'
            : 'تم إلغاء المطابقة وإعادة الفئتين.']);
    }

    if ($action === 'rematch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo->beginTransaction();

        $matchesMade = rematch_pending($pdo, $uid);

        $pdo->commit();

        $message = $matchesMade > 0
            ? "تم العثور على {$matchesMade} مطابقة جديدة وتطبيقها بنجاح."
            : 'تم فحص كل الطلبات المعلّقة ولا توجد مطابقات جديدة حاليًا.';

        json_response(['message' => $message, 'matches_made' => $matchesMade]);
    }

    if ($action === 'export') {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE user_id = ?');
        $stmt->execute([$uid]);
        $students = $stmt->fetchAll();

        $fatherAliases = array_map('normalized_header', [
            'اسم الأب', 'اسم الاب', 'الأب', 'الاب',
            'اسم الوالد', 'والد الطالب',
            'father name', 'father',
        ]);

        $prepared = [];

        foreach ($students as $student) {
            $extra = json_decode($student['extra_fields'], true) ?: [];

            $father = '';
            foreach ($extra as $key => $value) {
                if (in_array(normalized_header((string) $key), $fatherAliases, true)) {
                    $father = (string) $value;
                    break;
                }
            }

            $prepared[] = [
                'university_id' => $student['university_id'],
                'full_name' => $student['full_name'],
                'father_name' => $father,
                'category' => $student['current_category'],
                'sort_key' => normalize_category((string) $student['current_category']),
            ];
        }

        usort($prepared, function (array $a, array $b): int {
            $byCategory = strnatcasecmp($a['sort_key'], $b['sort_key']);
            if ($byCategory !== 0) {
                return $byCategory;
            }

            return strnatcasecmp($a['full_name'], $b['full_name']);
        });

        $headers = ['الرقم الجامعي', 'اسم الطالب', 'اسم الأب', 'الفئة'];

        $rows = array_map(
            fn(array $r): array => [$r['university_id'], $r['full_name'], $r['father_name'], $r['category']],
            $prepared
        );

        xlsx_export($headers, $rows, 'alsarmini-swap-' . date('Y-m-d') . '.xlsx');
    }

    throw new RuntimeException('الطلب غير معروف.');
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $ignored) {
        }
    }

    // PDOException ترث من RuntimeException، لذلك تُستثنى صراحةً حتى لا تظهر رسائل SQL للمستخدم.
    $isUserError = !($error instanceof PDOException)
        && ($error instanceof InvalidArgumentException || $error instanceof RuntimeException);

    if ($isUserError) {
        json_response(['error' => $error->getMessage()], 400);
    }

    error_log('[swap] ' . $error);
    json_response(['error' => 'حدث خطأ داخلي غير متوقع. يرجى المحاولة مرة أخرى.'], 500);
}
