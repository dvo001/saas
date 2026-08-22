(() => {
    const root = document.documentElement;
    const preferred = window.matchMedia('(prefers-color-scheme: dark)');
    const stored = localStorage.getItem('color-theme');

    const apply = (theme) => {
        const resolved = theme === 'auto' ? (preferred.matches ? 'dark' : 'light') : theme;
        root.dataset.bsTheme = resolved;
    };

    apply(stored || 'auto');

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = root.dataset.bsTheme === 'dark' ? 'light' : 'dark';
            localStorage.setItem('color-theme', next);
            apply(next);
        });
    });

    const navButton = document.querySelector('[data-nav-toggle]');
    const nav = document.querySelector('[data-nav]');
    navButton?.addEventListener('click', () => {
        const open = nav?.classList.toggle('show') ?? false;
        navButton.setAttribute('aria-expanded', String(open));
    });

    const slugSource = document.querySelector('[data-slug-source]');
    const slugTarget = document.querySelector('[data-slug-target]');
    let slugWasEdited = Boolean(slugTarget?.value);
    slugTarget?.addEventListener('input', () => { slugWasEdited = true; });
    slugSource?.addEventListener('input', () => {
        if (slugWasEdited || !slugTarget) return;
        slugTarget.value = slugSource.value
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '').slice(0, 80);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
})();
