(() => {
    let trendChart;
    let volumeChart;
    let refreshTimer;
    let refreshDelay;
    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character]));
    const statusClass = status => ['success', 'failed', 'disabled'].includes(status) ? status : 'unknown';
    const formatBytes = bytes => {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = Number(bytes || 0), index = 0;
        while (size >= 1024 && index < units.length - 1) { size /= 1024; index++; }
        return `${size.toFixed(index > 1 ? 1 : 0)} ${units[index]}`;
    };
    const renderCharts = payload => {
        const labels = payload.trend.map(row => row.day.slice(5));
        const trendData = [
            {label: 'ناجحة', data: payload.trend.map(row => row.success), backgroundColor: '#198754'},
            {label: 'جزئية', data: payload.trend.map(row => row.partial), backgroundColor: '#ffb009'},
            {label: 'فاشلة', data: payload.trend.map(row => row.failed), backgroundColor: '#c33c54'}
        ];
        trendChart?.destroy();
        trendChart = new Chart(document.getElementById('backupTrend'), {type: 'bar', data: {labels, datasets: trendData}, options: {responsive: true, scales: {x: {stacked: true}, y: {stacked: true, beginAtZero: true, ticks: {precision: 0}}}, plugins: {legend: {position: 'bottom'}}}});
        volumeChart?.destroy();
        volumeChart = new Chart(document.getElementById('destinationVolume'), {type: 'doughnut', data: {labels: payload.destinations.map(row => `Server ${row.display_order}`), datasets: [{data: payload.destinations.map(row => Number(row.bytes)), backgroundColor: ['#2b0060', '#ffb009', '#168cb5', '#198754', '#c33c54']}]}, options: {responsive: true, plugins: {legend: {position: 'bottom'}}}});
    };
    const renderDestinations = destinations => {
        document.querySelector('[data-destination-health]').innerHTML = destinations.map(destination => `<div class="health-row"><span class="health-dot is-${statusClass(destination.enabled == 0 ? 'disabled' : destination.health_status)}"></span><div><strong>Server ${Number(destination.display_order)} · ${escapeHtml(destination.name)}</strong><small>${escapeHtml(destination.last_test_message || 'لم يختبر')} · ${destination.last_latency_ms ? Number(destination.last_latency_ms) + ' ms' : '-'}</small></div><span class="status status-${destination.health_status === 'success' ? 'success' : destination.health_status === 'failed' ? 'failed' : 'queued'}">${escapeHtml(destination.health_status)}</span></div>`).join('') || '<div class="empty">لا توجد وجهات.</div>';
    };
    const renderFailures = failures => {
        document.querySelector('[data-failure-list]').innerHTML = failures.map(failure => `<div class="failure-row"><span class="failure-icon"><i class="fa fa-exclamation"></i></span><div><strong>${escapeHtml(failure.error_code || failure.phase)}</strong><p>${escapeHtml(failure.message)}</p><code>${escapeHtml(failure.trace_id)}</code></div><small>${escapeHtml(failure.created_at)}</small></div>`).join('') || '<div class="empty">لا توجد أخطاء حديثة.</div>';
    };
    const refresh = async () => {
        try {
            const response = await fetch('api/dashboard.php', {headers: {'Accept': 'application/json'}});
            if (response.status === 401) { location.href = 'login.php'; return; }
            if (!response.ok) throw new Error('Dashboard request failed');
            const payload = await response.json();
            Object.entries(payload.stats).forEach(([key, value]) => document.querySelectorAll(`[data-stat="${key}"]`).forEach(node => node.textContent = value));
            document.querySelector('[data-stat-bytes]').textContent = formatBytes(payload.stats.bytes);
            document.querySelector('[data-dashboard-time]').textContent = payload.generated_at;
            renderCharts(payload); renderDestinations(payload.destinations); renderFailures(payload.failures);
            const nextDelay = Number(payload.refresh_seconds || 30) * 1000;
            if (nextDelay !== refreshDelay) {
                clearInterval(refreshTimer);
                refreshDelay = nextDelay;
                refreshTimer = setInterval(refresh, refreshDelay);
            }
            document.dispatchEvent(new CustomEvent('sqlbak:refreshed', {detail: {time: payload.generated_at}}));
        } catch (error) {
            document.dispatchEvent(new CustomEvent('sqlbak:refreshed', {detail: {time: 'فشل التحديث'}}));
        }
    };
    document.addEventListener('sqlbak:refresh', refresh);
    refresh();
})();
