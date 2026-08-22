(() => {
    const start = (workspace, prefix) => {
        const initialRevision = workspace.dataset[`${prefix}Revision`];
        const revisionUrl = workspace.dataset[`${prefix}RevisionUrl`];
        const warning = workspace.querySelector(`[data-${prefix}-stale]`);
        if (!initialRevision || !revisionUrl || !warning) return;

        let dirty = false;
        let delay = 15000;
        let timeout;
        let controller;
        workspace.addEventListener('input', () => { dirty = true; }, {passive: true});

        const schedule = (milliseconds = delay) => {
            window.clearTimeout(timeout);
            timeout = window.setTimeout(poll, milliseconds);
        };
        const poll = async () => {
            if (document.hidden || !navigator.onLine) { schedule(30000); return; }
            controller?.abort(); controller = new AbortController();
            try {
                const response = await fetch(revisionUrl, {
                    cache: 'no-store', credentials: 'same-origin', signal: controller.signal,
                    headers: {'Accept': 'application/json', 'If-None-Match': `"${initialRevision}"`},
                });
                if (response.status === 304) { delay = Math.min(60000, delay + 5000); schedule(); return; }
                if (!response.ok) { delay = 60000; schedule(); return; }
                const data = await response.json();
                if (data.revision !== initialRevision) {
                    if (!dirty) { window.location.reload(); return; }
                    warning.classList.remove('d-none');
                    return;
                }
                delay = Math.min(60000, delay + 5000);
            } catch (error) { if (error.name !== 'AbortError') delay = 60000; }
            schedule();
        };
        document.addEventListener('visibilitychange', () => { if (document.hidden) controller?.abort(); else schedule(1000); });
        window.addEventListener('online', () => schedule(1000));
        window.addEventListener('beforeunload', () => { window.clearTimeout(timeout); controller?.abort(); }, {once: true});
        schedule();
    };

    document.querySelectorAll('[data-running-revision]').forEach((workspace) => start(workspace, 'running'));
    document.querySelectorAll('[data-football-revision]').forEach((workspace) => start(workspace, 'football'));
})();
