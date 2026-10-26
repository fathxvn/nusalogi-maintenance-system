document.addEventListener('click', (event) => {
    const toggle = event.target instanceof Element ? event.target.closest('[data-password-toggle]') : null;
    if (!(toggle instanceof HTMLButtonElement)) return;
    const input = document.getElementById(toggle.dataset.passwordToggle ?? '');
    if (!(input instanceof HTMLInputElement)) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(visible));
    toggle.setAttribute('aria-label', `${visible ? 'Sembunyikan' : 'Tampilkan'} ${toggle.dataset.fieldLabel}`);
    toggle.textContent = visible ? 'Sembunyikan' : 'Lihat';
});

document.addEventListener('submit', (event) => {
    if (!(event.target instanceof HTMLFormElement) || !event.target.hasAttribute('data-auth-form')) return;
    const submit = event.target.querySelector('[data-submit-label]');
    if (submit instanceof HTMLButtonElement) {
        submit.dataset.originalLabel = submit.textContent ?? '';
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        submit.textContent = 'Memproses…';
    }
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-submit-label]').forEach((button) => {
        if (button instanceof HTMLButtonElement) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            if (button.dataset.originalLabel) button.textContent = button.dataset.originalLabel;
        }
    });
});
