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
})();
