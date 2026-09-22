(() => {
    document.querySelector('[data-running-print]')?.addEventListener('click', () => window.print());
    const search = document.querySelector('[data-running-participant-search]');
    document.querySelectorAll('[data-running-participant-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const detail = document.getElementById(button.getAttribute('aria-controls'));
            if (!detail) return;
            detail.hidden = !detail.hidden;
            button.setAttribute('aria-expanded', String(!detail.hidden));
        });
    });
    search?.addEventListener('input', () => {
        const terms = search.value.toLocaleLowerCase().trim().split(/\s+/).filter(Boolean);
        let visible = 0;
        document.querySelectorAll('[data-running-participant-row]').forEach((row) => {
            const match = terms.every((term) => row.dataset.searchName.includes(term));
            row.hidden = !match;
            const detail = row.nextElementSibling;
            if (detail?.matches('[data-running-participant-detail]') && !match) {
                detail.hidden = true;
                row.querySelector('[data-running-participant-edit]')?.setAttribute('aria-expanded', 'false');
            }
            if (match) visible++;
        });
        document.querySelector('[data-running-participant-empty]')?.classList.toggle('d-none', visible > 0);
    });
})();
