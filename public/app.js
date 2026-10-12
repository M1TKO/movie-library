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