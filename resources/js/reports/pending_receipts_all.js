document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.$ === 'undefined') {
        return;
    }

    const root = document.getElementById('pendingReceiptsPage');

    if (!root) {
        return;
    }

    const $ = window.$;

    if ($('#datatable').length) {
        $('#datatable').DataTable({
            responsive: true,
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            lengthChange: true,
            autoWidth: false
        });
    }

    if (typeof window.ApexCharts === 'undefined') {
        return;
    }

    const pendingBySuppliers = JSON.parse(root.dataset.pendingBySupplier || '[]');
    const pendingTrend       = JSON.parse(root.dataset.pendingTrend || '[]');

    const commonOptions = {
        chart: {
            toolbar: { show: false },
            fontFamily: 'inherit'
        },
        dataLabels: { enabled: false },
        grid: { borderColor: '#e2e8f0' },
        legend: { position: 'top' }
    };

    const supplierChartEl = document.querySelector('#pendingBySuppliersChart');
    const trendChartEl    = document.querySelector('#pendingTrendChart');

    if (supplierChartEl && pendingBySuppliers.length) {
        new window.ApexCharts(supplierChartEl, {
            ...commonOptions,
            chart: { ...commonOptions.chart, type: 'bar', height: 320 },
            series: [{ name: 'Valor Pendente (€)', data: pendingBySuppliers.map(i => i.value) }],
            colors: ['#ef4444'],
            plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
            xaxis: { categories: pendingBySuppliers.map(i => i.supplier) },
            tooltip: { y: { formatter: val => val.toFixed(2) + ' €' } }
        }).render();
    }

    if (trendChartEl && pendingTrend.length) {
        new window.ApexCharts(trendChartEl, {
            ...commonOptions,
            chart: { ...commonOptions.chart, type: 'bar', height: 320 },
            series: [{ name: 'Valor Pendente (€)', data: pendingTrend.map(i => i.pending) }],
            colors: ['#f59e0b'],
            plotOptions: { bar: { borderRadius: 4 } },
            xaxis: { categories: pendingTrend.map(i => i.month) },
            tooltip: { y: { formatter: val => val.toFixed(2) + ' €' } }
        }).render();
    }
});
