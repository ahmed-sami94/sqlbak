(() => {
    const type = document.querySelector('[data-destination-type]');
    if (!type) return;
    const refresh = updatePort => {
        const selected = type.value;
        document.querySelectorAll('.remote-field').forEach(field => field.hidden = selected === 'local');
        document.querySelectorAll('.ftp-field').forEach(field => field.hidden = selected !== 'ftp');
        document.querySelectorAll('.sftp-field').forEach(field => field.hidden = selected !== 'sftp');
        const port = document.querySelector('[name="port"]');
        if (updatePort && port && !port.dataset.touched) port.value = selected === 'sftp' ? '22' : '21';
    };
    document.querySelector('[name="port"]')?.addEventListener('input', event => event.target.dataset.touched = '1');
    type.addEventListener('change', () => refresh(true));
    refresh(false);
    document.addEventListener('sqlbak:refresh', () => location.reload());
})();
