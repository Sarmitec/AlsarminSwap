(() => {
    'use strict';

    const EYE_ICON =
        '<svg class="icon-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
        '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>' +
        '<circle cx="12" cy="12" r="3"></circle>' +
        '</svg>';

    const EYE_OFF_ICON =
        '<svg class="icon-eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
        '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>' +
        '<line x1="1" y1="1" x2="23" y2="23"></line>' +
        '</svg>';

    const LABEL_SHOW = 'إظهار كلمة المرور';
    const LABEL_HIDE = 'إخفاء كلمة المرور';

    const fields = [];

    function enhance(input) {
        const wrapper = document.createElement('div');
        wrapper.className = 'password-field';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        input.setAttribute('autocapitalize', 'off');
        input.setAttribute('autocorrect', 'off');
        input.setAttribute('spellcheck', 'false');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'toggle-password';
        button.innerHTML = EYE_ICON + EYE_OFF_ICON;
        wrapper.appendChild(button);

        function render() {
            const visible = input.type === 'text';
            button.classList.toggle('is-visible', visible);
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            button.setAttribute('aria-label', visible ? LABEL_HIDE : LABEL_SHOW);
            button.title = visible ? LABEL_HIDE : LABEL_SHOW;
        }

        function hide() {
            if (input.type !== 'password') {
                input.type = 'password';
                render();
            }
        }

        button.addEventListener('mousedown', (event) => event.preventDefault());

        button.addEventListener('click', () => {
            const start = input.selectionStart;
            const end = input.selectionEnd;

            input.type = input.type === 'password' ? 'text' : 'password';
            render();
            input.focus();

            try {
                input.setSelectionRange(start, end);
            } catch (error) {
                /* بعض المتصفحات لا تدعم ضبط المؤشر بعد تغيير النوع */
            }
        });

        render();
        fields.push(hide);
    }

    function init() {
        document.querySelectorAll('.auth-form input[type="password"]').forEach(enhance);

        document.querySelectorAll('.auth-form').forEach((form) => {
            form.addEventListener('submit', hideAll);
        });
    }

    function hideAll() {
        fields.forEach((hide) => hide());
    }

    window.addEventListener('pageshow', hideAll);
    window.addEventListener('pagehide', hideAll);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            hideAll();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
