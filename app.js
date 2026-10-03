const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
const state = { students: [], requests: [], matches: [] };

const $ = (id) => document.getElementById(id);

function esc(v) {
    return String(v ?? '').replace(/[&<>'"]/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;',
    })[c]);
}

let noticeTimer = null;

function notice(message, error = false) {
    const e = $('notice');
    e.textContent = message;
    e.className = 'notice' + (error ? ' error' : '');
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => e.classList.add('hidden'), 5000);
}

async function api(action, options = {}) {
    const headers = new Headers(options.headers || {});
    if ((options.method || 'GET').toUpperCase() === 'POST') {
        headers.set('X-CSRF-Token', CSRF);
    }
    const r = await fetch(`api.php?action=${action}`, { ...options, headers });
    let d;
    try {
        d = await r.json();
    } catch {
        throw Error('استجابة غير صالحة من الخادم');
    }

    if (r.status === 401) {
        window.location.href = 'login.php';
        throw Error(d.error || 'انتهت الجلسة. يرجى تسجيل الدخول.');
    }

    if (!r.ok || d.error) {
        throw Error(d.error || 'حدث خطأ غير متوقع');
    }

    return d;
}

function label(s) {
    return {
        pending: 'قيد الانتظار',
        matched: 'تمت المطابقة',
        cancelled: 'ملغى',
    }[s] || s;
}

function showTab(id) {
    document.querySelectorAll('.view').forEach((v) => v.classList.toggle('active', v.id === id));
    document.querySelectorAll('nav button').forEach((b) => b.classList.toggle('active', b.dataset.tab === id));
}

function swapMode() {
    return document.querySelector('input[name="swapMode"]:checked')?.value === 'direct' ? 'direct' : 'auto';
}

function renderPartnerOptions() {
    const select = $('partnerSelect');
    const prev = select.value;
    const selfId = String($('studentSelect').value);
    const pendingIds = new Set(state.requests.filter((r) => r.status === 'pending').map((r) => String(r.student_id)));

    select.innerHTML = '<option value="">اختر الطالب البديل</option>' + state.students
        .filter((x) => String(x.id) !== selfId)
        .map((x) => {
            const busy = pendingIds.has(String(x.id));
            return `<option value="${x.id}"${busy ? ' disabled' : ''}>${esc(x.university_id)} - ${esc(x.full_name)}${busy ? ' (لديه طلب قائم)' : ''}</option>`;
        }).join('');

    if ([...select.options].some((o) => o.value === prev && !o.disabled)) {
        select.value = prev;
    }

    const p = state.students.find((x) => String(x.id) === String(select.value));
    $('partnerCategory').value = p?.current_category || '';
}

function updateSwapModeUI() {
    const direct = swapMode() === 'direct';
    $('partnerWrap').classList.toggle('hidden', !direct);
    $('partnerCategoryWrap').classList.toggle('hidden', !direct);
    $('toCategoryWrap').classList.toggle('hidden', direct);
    $('toCategory').required = !direct;
    $('partnerSelect').required = direct;
    $('requestSubmit').textContent = direct ? 'تنفيذ التبديل المباشر' : 'تسجيل الطلب';
    $('modeHint').textContent = direct
        ? 'يتم التبديل فورًا بين الطالبين المختارين دون المرور بقائمة الانتظار، وتُؤخذ الفئة المطلوبة تلقائيًا من فئة الآخر.'
        : 'يدخل الطلب قائمة الانتظار ويُطابَق تلقائيًا مع أي طالب مناسب وفق أولوية التسجيل.';
}

function render() {
    const s = state.stats;
    $('statStudents').textContent = s.students;
    $('statPending').textContent = s.pending;
    $('statMatched').textContent = s.matched;

    const q = ($('studentSearch').value || '').toLowerCase();

    $('studentsBody').innerHTML = state.students
        .filter((x) => (x.university_id + ' ' + x.full_name).toLowerCase().includes(q))
        .map((x) => `<tr>
            <td>${esc(x.university_id)}</td>
            <td>${esc(x.full_name)}</td>
            <td>${esc(x.original_category)}</td>
            <td><b>${esc(x.current_category)}</b></td>
        </tr>`).join('') || '<tr><td colspan="4">لا توجد بيانات طلاب.</td></tr>';

    const prevStudent = $('studentSelect').value;
    $('studentSelect').innerHTML = '<option value="">اختر الطالب</option>' + state.students.map((x) => `<option value="${x.id}">${esc(x.university_id)} - ${esc(x.full_name)}</option>`).join('');
    if ([...$('studentSelect').options].some((o) => o.value === prevStudent)) {
        $('studentSelect').value = prevStudent;
    }
    $('fromCategory').value = state.students.find((x) => String(x.id) === String($('studentSelect').value))?.current_category || '';
    renderPartnerOptions();

    const f = $('requestFilter').value;

    $('requestsBody').innerHTML = state.requests
        .filter((x) => !f || x.status === f)
        .map((x) => `<tr>
            <td>${esc(x.full_name)}<small>${esc(x.university_id)}</small></td>
            <td class="route">${esc(x.to_category)} <em>←</em> ${esc(x.from_category)}</td>
            <td>${esc(x.created_at)}</td>
            <td><span class="status ${x.status === 'pending' ? 'pending-badge' : x.status === 'matched' ? 'matched-badge' : 'cancelled-badge'}">${label(x.status)}</span></td>
            <td>${x.status === 'pending' ? `<button class="btn-danger cancel-request" data-id="${x.id}">إلغاء</button>` : ''}</td>
        </tr>`).join('') || '<tr><td colspan="5">لا توجد طلبات.</td></tr>';

    $('matchesList').innerHTML = state.matches.map((x) => `<article class="match-card">
        <div class="match-title">✓ تمت المطابقة #${x.id} · ${esc(x.matched_at)}${x.match_type === 'direct' ? ' <span class="badge direct-badge">تبديل مباشر</span>' : ''}</div>
        <div class="binary">
            <div class="binary-box">
                <strong>${esc(x.a_name)}</strong>
                <small>${esc(x.a_uid)}</small>
                <div><span class="category-current">${esc(x.a_from)}</span> ← <span class="category-new">${esc(x.a_to)}</span></div>
            </div>
            <div class="swap-arrow">⇄</div>
            <div class="binary-box">
                <strong>${esc(x.b_name)}</strong>
                <small>${esc(x.b_uid)}</small>
                <div><span class="category-current">${esc(x.b_from)}</span> ← <span class="category-new">${esc(x.b_to)}</span></div>
            </div>
        </div>
        <button class="btn-danger undo-match" data-id="${x.id}" style="margin-top:16px">إلغاء المطابقة</button>
    </article>`).join('') || '<div class="card">لا توجد مطابقات ناجحة حتى الآن.</div>';
}

async function load() {
    Object.assign(state, await api('state'));
    render();
}

async function change(action, id, confirmText) {
    if (!confirm(confirmText || (action === 'undo' ? 'هل تريد إلغاء هذه المطابقة؟' : 'هل تريد إلغاء هذا الطلب؟'))) {
        return;
    }

    try {
        const d = await api(action, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
        notice(d.message);
        await load();
    } catch (e) {
        notice(e.message, true);
    }
}

document.addEventListener('click', (e) => {
    const tab = e.target.closest('[data-tab]');

    if (tab) {
        showTab(tab.dataset.tab);
    }

    if (e.target.classList.contains('cancel-request')) {
        change('cancel', e.target.dataset.id);
    }

    if (e.target.classList.contains('undo-match')) {
        const m = state.matches.find((x) => String(x.id) === String(e.target.dataset.id));
        change('undo', e.target.dataset.id, m?.match_type === 'direct'
            ? 'سيتم إلغاء التبديل المباشر وإعادة فئتي الطالبين، وسيُلغى طلباهما نهائيًا ولن يعودا إلى قائمة الانتظار. هل تريد المتابعة؟'
            : undefined);
    }
});

$('studentSelect').addEventListener('change', (e) => {
    const s = state.students.find((x) => x.id == e.target.value);
    $('fromCategory').value = s?.current_category || '';
    renderPartnerOptions();
});

$('partnerSelect').addEventListener('change', (e) => {
    const p = state.students.find((x) => String(x.id) === String(e.target.value));
    $('partnerCategory').value = p?.current_category || '';
});

document.querySelectorAll('input[name="swapMode"]').forEach((radio) => radio.addEventListener('change', updateSwapModeUI));
updateSwapModeUI();

const fileDrop = $('fileDrop');
const fileDropText = $('fileDropText');
const excelFile = $('excelFile');
const fileDropDefaultText = fileDropText?.textContent || '';

function updateFileDropLabel() {
    const file = excelFile?.files[0];

    if (file) {
        const sizeKb = Math.round(file.size / 1024);
        fileDropText.textContent = `${file.name} (${sizeKb} كيلوبايت)`;
        fileDrop.classList.add('has-file');
    } else {
        fileDropText.textContent = fileDropDefaultText;
        fileDrop.classList.remove('has-file');
    }
}

if (fileDrop && fileDropText && excelFile) {
    excelFile.addEventListener('change', updateFileDropLabel);

['dragenter', 'dragover'].forEach((eventName) => {
    fileDrop.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        fileDrop.classList.add('drag-active');
    });
});

['dragleave', 'drop'].forEach((eventName) => {
    fileDrop.addEventListener(eventName, (e) => {
        e.preventDefault();
        e.stopPropagation();
        fileDrop.classList.remove('drag-active');
    });
});

fileDrop.addEventListener('drop', (e) => {
    const file = e.dataTransfer?.files?.[0];

    if (file) {
        excelFile.files = e.dataTransfer.files;
        updateFileDropLabel();
    }
});
}

let searchTimer = null;
$('studentSearch').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(render, 200);
});
$('requestFilter').addEventListener('change', render);
$('refreshBtn').addEventListener('click', load);

$('requestForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const mode = swapMode();
    const direct = mode === 'direct';

    if (direct) {
        const a = state.students.find((x) => String(x.id) === String($('studentSelect').value));
        const b = state.students.find((x) => String(x.id) === String($('partnerSelect').value));

        if (!a || !b) {
            notice('يرجى اختيار الطالب والطالب البديل.', true);
            return;
        }

        if (!confirm(`سيتم تبديل فئتي الطالبين فورًا:\n${a.full_name} (${a.current_category}) ⇄ ${b.full_name} (${b.current_category})\nهل تريد المتابعة؟`)) {
            return;
        }
    }

    $('requestSubmit').disabled = true;

    const payload = direct
        ? { mode: 'direct', student_id: $('studentSelect').value, partner_id: $('partnerSelect').value }
        : { mode: 'auto', student_id: $('studentSelect').value, to_category: $('toCategory').value };

    try {
        const d = await api('request', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        notice(d.message);
        e.target.reset();
        $('modeDirect').checked = direct;
        $('modeAuto').checked = !direct;
        $('fromCategory').value = '';
        $('partnerCategory').value = '';
        updateSwapModeUI();
        await load();
    } catch (x) {
        notice(x.message, true);
    } finally {
        $('requestSubmit').disabled = false;
    }
});

if ($('importForm')) $('importForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    if ($('replaceMode').checked) {
        const sure = confirm('سيتم حذف جميع بياناتك الحالية نهائيًا واستبدالها بمحتوى الملف الجديد فقط. هل أنت متأكد من المتابعة؟');

        if (!sure) {
            return;
        }
    }

    const importBtn = e.target.querySelector('button[type="submit"]');
    importBtn.disabled = true;

    try {
        const d = await api('import', { method: 'POST', body: new FormData(e.target) });
        notice(d.message);

        $('importResult').innerHTML = d.errors.length
            ? `<p class="notice error">تم تجاوز ${d.errors.length} صفوف: ${d.errors.map((x) => `السطر ${x.line}: ${esc(x.reason)}`).join(' · ')}</p>`
            : '';

        e.target.reset();
        updateFileDropLabel();
        await load();
    } catch (x) {
        notice(x.message, true);
    } finally {
        importBtn.disabled = false;
    }
});

if ($('resetDataBtn')) $('resetDataBtn').addEventListener('click', async () => {
    const step1 = confirm('سيتم مسح جميع بياناتك (الطلاب وطلبات التبديل والمطابقات) نهائيًا ولن يمكن التراجع عن ذلك. هل تريد المتابعة؟');

    if (!step1) {
        return;
    }

    const step2 = confirm('تأكيد أخير: هذا الإجراء لا رجعة فيه. هل أنت متأكد تمامًا؟');

    if (!step2) {
        return;
    }

    try {
        const d = await api('reset', { method: 'POST' });
        notice(d.message);
        await load();
    } catch (e) {
        notice(e.message, true);
    }
});

load().catch((e) => notice(e.message, true));

$('rematchBtn').addEventListener('click', async () => {
    try {
        const d = await api('rematch', { method: 'POST' });
        notice(d.message);
        await load();
    } catch (e) {
        notice(e.message, true);
    }
});
