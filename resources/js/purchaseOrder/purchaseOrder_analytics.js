document.addEventListener('DOMContentLoaded', () => {
    if (typeof window.$ === 'undefined') {
        return;
    }

    const root = document.getElementById('purchaseOrderAnalyticsPage');

    if (!root) {
        return;
    }

    const $ = window.$;
    const statusBreakdown = JSON.parse(root.dataset.statusBreakdown || '{}');
    const topSuppliers = JSON.parse(root.dataset.topSuppliers || '[]');
    const monthlyTrend = JSON.parse(root.dataset.monthlyTrend || '[]');
    const topPendingOrders = JSON.parse(root.dataset.topPendingOrders || '[]');

    if ($('#analyticsTable').length) {
        $('#analyticsTable').DataTable({
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

    const commonOptions = {
        chart: {
            toolbar: { show: false },
            fontFamily: 'inherit'
        },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 3 },
        grid: { borderColor: '#e2e8f0' },
        legend: { position: 'top' }
    };

    const statusChartElement = document.querySelector('#purchaseOrderStatusChart');
    const suppliersChartElement = document.querySelector('#purchaseOrderSupplierChart');
    const trendChartElement = document.querySelector('#purchaseOrderTrendChart');
    const pendingChartElement = document.querySelector('#purchaseOrderPendingChart');

    if (statusChartElement) {
        new window.ApexCharts(statusChartElement, {
            ...commonOptions,
            chart: {
                ...commonOptions.chart,
                type: 'donut',
                height: 320
            },
            series: Object.values(statusBreakdown),
            labels: Object.keys(statusBreakdown),
            colors: ['#16a34a', '#f59e0b', '#64748b'],
            stroke: { width: 0 },
            legend: { position: 'bottom' }
        }).render();
    }

    if (suppliersChartElement) {
        new window.ApexCharts(suppliersChartElement, {
            ...commonOptions,
            chart: {
                ...commonOptions.chart,
                type: 'bar',
                height: 320
            },
            series: [{
                name: 'Valor Encomendado',
                data: topSuppliers.map(item => item.value)
            }],
            colors: ['#2563eb'],
            plotOptions: {
                bar: {
                    borderRadius: 6,
                    horizontal: true
                }
            },
            xaxis: {
                categories: topSuppliers.map(item => item.supplier)
            }
        }).render();
    }

    if (trendChartElement) {
        new window.ApexCharts(trendChartElement, {
            ...commonOptions,
            chart: {
                ...commonOptions.chart,
                type: 'area',
                height: 320
            },
            colors: ['#2563eb', '#10b981'],
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.35,
                    opacityTo: 0.05
                }
            },
            series: [
                {
                    name: 'Valor Encomendado',
                    data: monthlyTrend.map(item => item.ordered)
                },
                {
                    name: 'Valor Recebido',
                    data: monthlyTrend.map(item => item.received)
                }
            ],
            xaxis: {
                categories: monthlyTrend.map(item => item.month)
            }
        }).render();
    }

    if (pendingChartElement) {
        new window.ApexCharts(pendingChartElement, {
            ...commonOptions,
            chart: {
                ...commonOptions.chart,
                type: 'bar',
                height: 320
            },
            series: [{
                name: 'Valor Pendente',
                data: topPendingOrders.map(item => item.value)
            }],
            colors: ['#ef4444'],
            plotOptions: {
                bar: {
                    borderRadius: 6,
                    horizontal: true
                }
            },
            xaxis: {
                categories: topPendingOrders.map(item => item.label)
            }
        }).render();
    }
});
