export function setupForms() {
    document.querySelector('[data-demo-email]')?.addEventListener('change', event => {
        document.querySelector('#email').value = event.target.value;
    });
    document.querySelectorAll('[data-password]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.password);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Ocultar' : 'Ver';
        button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        button.setAttribute('aria-pressed', String(show));
    }));
    document.querySelectorAll('[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
    document.querySelectorAll('[data-upload-form]').forEach(form => {
        const fileInput = form.querySelector('input[type=file]');
        fileInput?.addEventListener('change', () => {
            const file = fileInput.files[0];
            fileInput.setCustomValidity('');
            form.querySelector('[data-file-name]').textContent = file ? `${file.name} · ${(file.size / 1024 / 1024).toFixed(2)} MB` : 'Hasta 10 MB';
            if (file && file.size > 10 * 1024 * 1024) fileInput.setCustomValidity('El PDF supera los 10 MB.');
        });
    });
    const start = document.querySelector('#start_date');
    const end = document.querySelector('#end_date');
    const updateDates = () => { if (start && end) end.min = start.value; };
    start?.addEventListener('change', updateDates);
    updateDates();
}
