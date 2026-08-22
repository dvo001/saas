(() => {
    const workspace = document.querySelector('[data-football-revision]');
    if (!workspace) return;
    const initialRevision = workspace.dataset.footballRevision;
    const revisionUrl = workspace.dataset.footballRevisionUrl;
    const warning = workspace.querySelector('[data-football-stale]');
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
            // A transient polling failure must not interrupt local match entry.
        }
    }, 15000);
})();
