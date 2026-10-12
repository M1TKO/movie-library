'use strict';

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-path]');
    if (!button) return;
    const container = button.closest('.copy-path');
    const path = container.querySelector('code');
    const status = container.querySelector('[role="status"]');
    try {
        await navigator.clipboard.writeText(path.textContent);
        status.textContent = 'Path copied.';
    } catch {
        const range = document.createRange();
        range.selectNodeContents(path);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        status.textContent = 'Clipboard unavailable. The path is selected; use your browser Copy command.';
    }
});
document.addEventListener('htmx:beforeRequest', () => {
    const status = document.getElementById('request-status');
    status.hidden = false;
    status.textContent = 'Updating background job status…';
});
document.addEventListener('htmx:afterRequest', (event) => {
    const status = document.getElementById('request-status');
    status.hidden = event.detail.successful;
    status.textContent = event.detail.successful ? '' : 'Progress could not be refreshed. Saved results remain available; retrying automatically. Reload this page to retry now.';
});
for (const name of ['htmx:sendError', 'htmx:timeout']) {
    document.addEventListener(name, () => {
        const status = document.getElementById('request-status');
        status.hidden = false;
        status.textContent = 'Connection unavailable. Saved results remain available; retrying automatically. Reload this page to retry now.';
    });
}
