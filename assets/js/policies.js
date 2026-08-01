(() => {
    const typeSelect = document.querySelector('[data-schedule-type]');
    if (!typeSelect) return;
    const refreshFields = () => {
        const type = typeSelect.value;
        document.querySelectorAll('[data-schedule-field]').forEach(field => {
            const target = field.dataset.scheduleField;
            field.hidden = target === 'interval' ? type !== 'interval' : target === 'time' ? type === 'interval' : target !== type;
        });
    };
    typeSelect.addEventListener('change', refreshFields);
    refreshFields();
})();
