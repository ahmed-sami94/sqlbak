(() => {
    const frequency = document.querySelector('[data-report-frequency]');
    if (!frequency) return;
    const refresh = () => document.querySelectorAll('[data-report-field]').forEach(field => field.hidden = field.dataset.reportField !== frequency.value);
    frequency.addEventListener('change', refresh);
    refresh();
})();
