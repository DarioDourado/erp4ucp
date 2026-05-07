@extends('admin.admin_master')

@section('admin')

<div class="page-content"
     id="purchaseOrderAnalyticsPage"
     data-status-breakdown='@json($analyticsData["statusBreakdown"])'
     data-top-suppliers='@json($analyticsData["topSuppliers"])'
     data-monthly-trend='@json($analyticsData["monthlyTrend"])'
     data-top-pending-orders='@json($analyticsData["topPendingOrders"])'>
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Análise de Encomendas a Fornecedores</h4>
                </div>
            </div>
        </div>

        <div class="analytics-toolbar">
            <div>
                <h4 class="mb-1">Dashboard Operacional</h4>
                <p class="text-muted mb-0">Indicadores, gráficos e listagem analítica das encomendas a fornecedores.</p>
            </div>

            <form method="get" action="{{ route('purchaseOrder.analytics') }}" class="analytics-filter-form">
                <div>
                    <label for="status" class="form-label mb-1">Filtro de satisfação</label>
                    <select name="status" id="status" class="form-select">
                        <option value="all" {{ $statusFilter === 'all' ? 'selected' : '' }}>Todas</option>
                        <option value="full" {{ $statusFilter === 'full' ? 'selected' : '' }}>Encomendas satisfeitas</option>
                        <option value="pending" {{ $statusFilter === 'pending' ? 'selected' : '' }}>Por satisfazer</option>
                        <option value="none" {{ $statusFilter === 'none' ? 'selected' : '' }}>Sem entradas</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Aplicar</button>
                </div>
            </form>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Total de encomendas</div>
                    <div class="analytics-kpi-value">{{ number_format($analyticsData['totals']['orders'], 0, ',', '.') }}</div>
                    <div class="analytics-kpi-note">Filtro atual aplicado.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Valor encomendado</div>
                    <div class="analytics-kpi-value">{{ number_format($analyticsData['totals']['orderedValue'], 2, ',', '.') }}</div>
                    <div class="analytics-kpi-note">Base monetária das encomendas filtradas.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Valor recebido</div>
                    <div class="analytics-kpi-value">{{ number_format($analyticsData['totals']['deliveredValue'], 2, ',', '.') }}</div>
                    <div class="analytics-kpi-note">Receção acumulada com base nas entradas.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Satisfação média</div>
                    <div class="analytics-kpi-value">{{ number_format($analyticsData['totals']['averageSatisfaction'], 2, ',', '.') }}%</div>
                    <div class="analytics-kpi-note">Percentagem média de entrega por encomenda.</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <div class="analytics-chart-card">
                    <div class="analytics-chart-title">Distribuição Global</div>
                    <div class="analytics-chart-box" id="purchaseOrderStatusChart"></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analytics-chart-card">
                    <div class="analytics-chart-title">Top Fornecedores por Valor</div>
                    <div class="analytics-chart-box" id="purchaseOrderSupplierChart"></div>
                </div>
            </div>
            <div class="col-xl-8">
                <div class="analytics-chart-card">
                    <div class="analytics-chart-title">Tendência Mensal</div>
                    <div class="analytics-chart-box" id="purchaseOrderTrendChart"></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="analytics-chart-card">
                    <div class="analytics-chart-title">Top Encomendas Pendentes</div>
                    <div class="analytics-chart-box" id="purchaseOrderPendingChart"></div>
                </div>
            </div>
        </div>

        <div class="analytics-table-card">
            <div class="analytics-table-title">Listagem Analítica</div>
            <div class="table-responsive">
                <table id="analyticsTable"
                       class="table table-bordered dt-responsive nowrap"
                       style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                    <thead>
                        <tr>
                            <th>Ln</th>
                            <th>Nº Encomenda</th>
                            <th>Fornecedor</th>
                            <th>Data</th>
                            <th class="text-center">Estado</th>
                            <th class="text-end">Valor Encomendado</th>
                            <th class="text-end">Valor Recebido</th>
                            <th class="text-end">Valor Pendente</th>
                            <th class="text-end">% Satisfação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($filteredPurchaseOrders as $key => $item)
                            <tr>
                                <td>{{ $key + 1 }}</td>
                                <td>{{ $item->pONumber }}</td>
                                <td>{{ $item->supplierCode }} - {{ optional($item->supplierLink)->name }}</td>
                                <td>{{ $item->pODate ? date('d/m/Y', strtotime($item->pODate)) : '' }}</td>
                                <td class="text-center">
                                    <span class="purchase-order-status-badge status-{{ $item->satisfactionFilter }}">
                                        {{ $item->satisfactionLabel }}
                                    </span>
                                </td>
                                <td class="text-end">{{ number_format($item->orderedValueSummary, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($item->deliveredValueSummary, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($item->pendingValueSummary, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($item->satisfactionPercent, 2, ',', '.') }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
    @vite('resources/css/purchaseOrder/purchaseOrder_analytics.css')
@endpush

@push('scripts')
    <script src="{{ asset('backend/assets/libs/apexcharts/apexcharts.min.js') }}"></script>
    @vite('resources/js/purchaseOrder/purchaseOrder_analytics.js')
@endpush
