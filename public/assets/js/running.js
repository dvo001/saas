(() => {
    document.querySelector('[data-running-print]')?.addEventListener('click', () => window.print());
    const workspace = document.querySelector('[data-running-revision]');
    if (!workspace) return;

    const initialRevision = workspace.dataset.runningRevision;
    const revisionUrl = workspace.dataset.runningRevisionUrl;
    const warning = workspace.querySelector('[data-running-stale]');
    if (!initialRevision || !revisionUrl || !warning) return;
    let dirty = false;
    workspace.addEventListener('input', () => { dirty = true; });

    window.setInterval(async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(revisionUrl, {headers: {'Accept': 'application/json'}});
            if (!response.ok) return;
            const data = await response.json();
            if (data.revision !== initialRevision) {
                if (!dirty) window.location.reload();
                else warning.classList.remove('d-none');
            }
        } catch (_) {
            // A transient polling failure must not interrupt local data entry.
        }
    }, 15000);
})();
