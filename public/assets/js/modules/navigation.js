export function setupNavigation() {
    const button = document.querySelector('[data-menu]');
    const sidebar = document.querySelector('#sidebar');
    const overlay = document.querySelector('[data-close-menu]');
    const close = () => { sidebar?.classList.remove('open'); overlay?.classList.remove('show'); button?.setAttribute('aria-expanded', 'false'); };
    button?.addEventListener('click', () => {
        const open = sidebar.classList.toggle('open');
        overlay.classList.toggle('show', open);
        button.setAttribute('aria-expanded', String(open));
    });
    overlay?.addEventListener('click', close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
}
