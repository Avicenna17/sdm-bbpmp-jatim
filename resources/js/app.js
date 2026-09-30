import Chart from 'chart.js/auto';

const colors = ['#2563eb', '#facc15', '#93c5fd', '#fde68a', '#1e3a8a', '#ca8a04', '#64748b'];
function renderCharts() {
for (const canvas of document.querySelectorAll('[data-public-chart]')) {
    const values = JSON.parse(canvas.dataset.values);
    const type = canvas.dataset.chartType;
    const barColor = canvas.id === 'projection-RETIREMENT' ? '#ef4444' : canvas.id === 'projection-REQUIREMENT' ? '#16a34a' : '#2563eb';
    const horizontal = canvas.dataset.horizontal === 'true';
    new Chart(canvas, {
        type,
        data: {
            labels: values.map(item => item.label),
            datasets: [{ label: 'Jumlah', data: values.map(item => item.value), backgroundColor: type === 'doughnut' ? colors : barColor, borderRadius: type === 'bar' ? 5 : 0, borderWidth: type === 'doughnut' ? 3 : 0 }],
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 300 },
            indexAxis: horizontal ? 'y' : 'x',
            plugins: { legend: { display: type === 'doughnut', position: 'bottom' }, tooltip: { callbacks: { label: context => `Jumlah: ${context.raw ?? '-'}` } } },
            ...(type === 'bar' ? { scales: { [horizontal ? 'x' : 'y']: { beginAtZero: true, ticks: { precision: 0 } }, [horizontal ? 'y' : 'x']: { grid: { display: false }, ticks: { autoSkip: false, callback: function(value) { const label = this.getLabelForValue(value); return label.length > 36 ? label.slice(0, 33) + '…' : label; } } } } } : {}),
        },
    });
}

}
renderCharts();

// Refresh the server-rendered aggregates while retaining scroll and keyboard focus.
let pendingRequest;
let searchTimer;
let revision = 0;
async function refreshDashboard(url) {
    clearTimeout(searchTimer);
    pendingRequest?.abort();
    const controller = new AbortController();
    pendingRequest = controller;
    const requestRevision = ++revision;
    const main = document.querySelector('#main');
    main.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(url, { signal: controller.signal, headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error('Dashboard request failed');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const replacement = page.querySelector('#main');
        if (!replacement) throw new Error('Dashboard content missing');
        if (requestRevision !== revision) return;
        const focused = main.contains(document.activeElement) ? document.activeElement : null;
        const focusName = focused?.name;
        const focusValue = focused?.value;
        const selection = focused?.type === 'search' ? [focused.selectionStart, focused.selectionEnd] : null;
        const scroll = [window.scrollX, window.scrollY];
        for (const canvas of main.querySelectorAll('[data-public-chart]')) Chart.getChart(canvas)?.destroy();
        main.replaceWith(replacement);
        const periodBadge = document.querySelector('[data-public-period]');
        if (periodBadge) periodBadge.textContent = page.querySelector('[data-public-period]')?.textContent ?? '';
        history.replaceState(null, '', url);
        renderCharts();
        if (focusName) {
            const control = [...replacement.querySelectorAll('[name]')].find(el => el.name === focusName && (el.type !== 'radio' || el.value === focusValue));
            control?.focus({ preventScroll: true });
            if (selection && control?.type === 'search') control.setSelectionRange(...selection);
        }
        window.scrollTo({ left: scroll[0], top: scroll[1], behavior: 'instant' });
    } catch (error) {
        if (error.name !== 'AbortError' && requestRevision === revision) window.location.assign(url);
    } finally {
        if (requestRevision === revision) document.querySelector('#main')?.removeAttribute('aria-busy');
    }
}
function applyForm(form) {
    const url = new URL(form.action);
    url.search = new URLSearchParams(new FormData(form)).toString();
    refreshDashboard(url);
}
document.addEventListener('submit', event => {
    if (!event.target.matches('[data-dashboard-filter]')) return;
    event.preventDefault();
    applyForm(event.target);
});
document.addEventListener('change', event => {
    if (event.target.matches('[data-auto-filter]')) applyForm(event.target.form);
});
document.addEventListener('input', event => {
    if (!event.target.matches('[data-auto-search]') || event.isComposing) return;
    clearTimeout(searchTimer);
    pendingRequest?.abort();
    ++revision;
    searchTimer = setTimeout(() => applyForm(event.target.form), 400);
});
document.addEventListener('click', event => {
    const link = event.target.closest('[data-filter-reset]');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
    event.preventDefault();
    refreshDashboard(link.href);
});
