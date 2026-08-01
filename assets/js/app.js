(() => {
    document.querySelector('[data-menu-toggle]')?.addEventListener('click', () => document.body.classList.toggle('menu-open'));
    const refreshButton = document.querySelector('[data-refresh]');
    refreshButton?.addEventListener('click', () => {
        if (['dashboard', 'storage'].includes(document.body.dataset.page)) {
            refreshButton.classList.add('is-loading');
            document.dispatchEvent(new CustomEvent('sqlbak:refresh', {detail: {manual: true}}));
            return;
        }
        location.reload();
    });
    document.addEventListener('sqlbak:refreshed', event => {
        refreshButton?.classList.remove('is-loading');
        const stamp = document.querySelector('[data-last-refresh]');
        if (stamp) stamp.textContent = event.detail?.time || '';
    });
    document.querySelectorAll('form input[name="action"][value="save"]').forEach(actionInput => {
        actionInput.closest('form')?.querySelector('input[name="enabled"]')?.closest('label')?.remove();
    });
    const pendingLabels = new Map([
        ['save', 'جارٍ الحفظ...'],
        ['save_general', 'جارٍ الحفظ...'],
        ['save_mail', 'جارٍ الحفظ...'],
        ['add', 'جارٍ الحفظ...'],
        ['send_now', 'جارٍ الإرسال...'],
        ['toggle', 'جارٍ التحديث...'],
        ['toggle_mail', 'جارٍ التحديث...'],
    ]);
    document.querySelectorAll('form').forEach(form => {
        const action = form.querySelector('input[name="action"]')?.value;
        if (!pendingLabels.has(action)) return;
        form.addEventListener('submit', () => {
            const submitButton = form.querySelector('button[type="submit"], button:not([type])');
            if (!submitButton) return;
            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitButton.innerHTML = submitButton.classList.contains('icon-button')
                ? '<i class="fa fa-spinner fa-spin"></i>'
                : `<i class="fa fa-spinner fa-spin"></i> ${pendingLabels.get(action)}`;
        });
    });
})();
