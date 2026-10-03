<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
start_secure_session();
$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>Alsarmini Swap</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="header">
        <div class="brand"><span>⇄</span> Alsarmini Swap</div>
        <nav>
            <button data-tab="students" class="active">الطلاب والاستيراد</button>
            <button data-tab="requests">طلبات التبديل</button>
            <button data-tab="matches">المطابقات</button>
            <button data-tab="export">التصدير</button>
        </nav>
        <div class="user-bar">
            <span class="user-name" title="<?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit">تسجيل الخروج</button>
            </form>
        </div>
    </header>

    <main class="container">
        <div id="notice" class="notice hidden"></div>

        <section id="students" class="view active">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">الخطوة الأولى</p>
                    <h2>استيراد بيانات الطلاب</h2>
                </div>
                <span class="badge completed">XLSX / CSV</span>
            </div>

            <div class="stats">
                <article>
                    <span>إجمالي الطلاب</span>
                    <strong id="statStudents">0</strong>
                </article>
                <article class="pending">
                    <span>طلبات قيد الانتظار</span>
                    <strong id="statPending">0</strong>
                </article>
                <article class="matched">
                    <span>التبديلات الناجحة</span>
                    <strong id="statMatched">0</strong>
                </article>
            </div>

            <div class="card intro">
                <h2>ابدأ من هنا</h2>
                <p>ارفع ملف الطلاب من النموذج أدناه، ثم سجّل طلبات التبديل من صفحة "طلبات التبديل". تتم المطابقة الثنائية تلقائيًا وفق أولوية التسجيل.</p>
            </div>

            <div class="card">
                <form id="importForm">
                    <label id="fileDrop" class="file-drop">
                        <input id="excelFile" type="file" name="file" accept=".xlsx,.csv" required>
                        <span class="upload-icon">↑</span>
                        <strong id="fileDropText">اسحب ملف Excel هنا أو اضغط للاختيار</strong>
                        <small>يدعم صيغ .xlsx و .csv — الحد الأقصى 10 ميجابايت</small>
                        <small>يكتشف البرنامج أعمدة الرقم الجامعي والاسم والفئة تلقائيًا حتى لو اختلف ترتيبها أو تسميتها، ولو كانت هناك صفوف عنوان إضافية قبلها.</small>
                    </label>
                    <label class="checkbox-row">
                        <input id="replaceMode" type="checkbox" name="mode" value="replace">
                        استبدال كل البيانات الحالية بالكامل بهذا الملف (بدل الدمج مع البيانات الموجودة)
                    </label>
                    <button class="btn-primary" type="submit">قراءة واستيراد الملف</button>
                </form>
                <div id="importResult"></div>
            </div>

            <div class="card danger-zone">
                <h3>إلغاء الملف المستورد</h3>
                <p>يمسح هذا الخيار كل بياناتك أنت فقط (الطلاب والطلبات والمطابقات) ولا يؤثر على حسابات المستخدمين الآخرين. لا يمكن التراجع عن هذا الإجراء.</p>
                <button id="resetDataBtn" class="btn-danger" type="button">إلغاء الملف المستورد ومسح كل البيانات</button>
            </div>

            <div class="card">
                <div class="toolbar">
                    <h3>الطلاب المحفوظون</h3>
                    <input id="studentSearch" placeholder="بحث بالرقم أو الاسم">
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>الرقم الجامعي</th>
                                <th>الاسم</th>
                                <th>الفئة الأصلية</th>
                                <th>الفئة الحالية</th>
                            </tr>
                        </thead>
                        <tbody id="studentsBody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="requests" class="view">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">تسجيل يدوي</p>
                    <h2>طلبات التبديل</h2>
                </div>
            </div>

            <div class="card">
                <form id="requestForm" class="form-grid">
                    <div class="swap-mode">
                        <label><input type="radio" name="swapMode" id="modeAuto" value="auto" checked> بدون بديل (قائمة الانتظار)</label>
                        <label><input type="radio" name="swapMode" id="modeDirect" value="direct"> مع بديل (تبديل مباشر)</label>
                    </div>
                    <p id="modeHint" class="swap-hint">يدخل الطلب قائمة الانتظار ويُطابَق تلقائيًا مع أي طالب مناسب وفق أولوية التسجيل.</p>
                    <label>الطالب
                        <select id="studentSelect" required>
                            <option value="">اختر الطالب</option>
                        </select>
                    </label>
                    <label>الفئة الحالية<input id="fromCategory" readonly></label>
                    <label id="partnerWrap" class="hidden">الطالب البديل
                        <select id="partnerSelect">
                            <option value="">اختر الطالب البديل</option>
                        </select>
                    </label>
                    <label id="partnerCategoryWrap" class="hidden">فئة البديل الحالية<input id="partnerCategory" readonly></label>
                    <label id="toCategoryWrap">الفئة المطلوبة<input id="toCategory" required placeholder="مثال: 8"></label>
                    <button id="requestSubmit" class="btn-primary" type="submit">تسجيل الطلب</button>
                </form>
            </div>

            <div class="card">
                <div class="toolbar">
                    <h3>قائمة الطلبات</h3>
                    <button id="rematchBtn" class="btn-secondary" type="button">تشغيل المطابقة التلقائية الشاملة</button>
                    <select id="requestFilter">
                        <option value="">كل الحالات</option>
                        <option value="pending">قيد الانتظار</option>
                        <option value="matched">تمت المطابقة</option>
                        <option value="cancelled">ملغى</option>
                    </select>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>الطالب</th>
                                <th>المسار</th>
                                <th>التاريخ</th>
                                <th>الحالة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="requestsBody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section id="matches" class="view">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">المحرك الأساسي</p>
                    <h2>نتائج التبديلات</h2>
                </div>
                <button id="refreshBtn" class="btn-secondary">تحديث البيانات</button>
            </div>

            <div id="matchesList"></div>
        </section>

        <section id="export" class="view">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">النتائج النهائية</p>
                    <h2>التصدير</h2>
                </div>
            </div>

            <div class="card export-panel">
                <div class="export-icon">↓</div>
                <h3>تصدير جميع الطلاب</h3>
                <p>ينشئ ملف XLSX يحتوي على طلاب حسابك فقط.</p>
                <a class="btn-primary" href="api.php?action=export">تصدير ملف Excel</a>
            </div>
        </section>
    </main>

    <footer>تم التصميم و التطوير بواسطة <span class="author">Abdullah Alsarmini</span></footer>
    <script src="app.js"></script>
</body>
</html>
