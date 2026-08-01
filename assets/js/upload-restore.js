(() => {
    const form = document.querySelector('[data-upload-restore-form]');
    if (!form) return;
    const mode = form.querySelector('[data-upload-restore-mode]');
    const fields = form.querySelectorAll('[data-upload-new]');
    const existing = form.querySelector('[data-upload-existing]');
    const name = form.querySelector('[data-upload-restore-file]');
    const fileName = form.querySelector('.upload-file-name');
    const refresh = () => {
        const creating = mode.value === 'new';
        fields.forEach(field => field.hidden = !creating);
        existing.hidden = creating;
    };
    mode.addEventListener('change', refresh);
    name.addEventListener('change', () => fileName.textContent = name.files[0]?.name || 'لم يتم اختيار ملف.');
    form.addEventListener('submit', event => {
        if (!confirm('سيتم تنفيذ SQL على قاعدة البيانات المحددة. هل تريد المتابعة؟')) event.preventDefault();
    });
    refresh();
})();
