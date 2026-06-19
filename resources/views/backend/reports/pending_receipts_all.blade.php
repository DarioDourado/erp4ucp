@extends('admin.admin_master')

@section('admin')

<div class="page-content"
     id="pendingReceiptsPage"
     data-pending-by-supplier='@json($chartPendingBySupplier)'
     data-pending-trend='@json($chartPendingTrend)'>
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Receção Pendente de Encomendas</h4>
                </div>
            </div>
        </div>

        <div class="analytics-toolbar">
            <div>
                <h4 class="mb-1">Encomendas com Receção em Falta</h4>
                <p class="text-muted mb-0">Encomendas onde o valor recebido é inferior ao valor encomendado. Clique no detalhe para ver os produtos pendentes.</p>
            </div>
            <div>
                <a href="{{ route('purchaseOrder.analytics') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="ri-bar-chart-line me-1"></i> Ver Analytics Gerais
                </a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Encomendas Pendentes</div>
                    <div class="analytics-kpi-value">{{ number_format($kpis['totalOrders'], 0, ',', '.') }}</div>
                    <div class="analytics-kpi-note">Com pelo menos 1 produto por receber.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Valor Total Encomendado</div>
                    <div class="analytics-kpi-value">{{ number_format($kpis['totalOrdered'], 2, ',', '.') }} €</div>
                    <div class="analytics-kpi-note">Soma das encomendas com pendentes.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Valor Já Recebido</div>
                    <div class="analytics-kpi-value">{{ number_format($kpis['totalReceived'], 2, ',', '.') }} €</div>
                    <div class="analytics-kpi-note">Mercadoria já entregue pelo fornecedor.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card pending-kpi-highlight">
                    <div class="analytics-kpi-label">Valor Por Receber</div>
                    <div class="analytics-kpi-value text-danger">{{ number_format($kpis['totalPending'], 2, ',', '.') }} €</div>
                    <div class="analytics-kpi-note">Total em falta de todos os fornecedores.</div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <div class="analytics-chart-card">
                    <div class="analytics-chart-title">Top Fornecedores — Valor Pendente</div>
                    <div class="analytics-chart-box" id="pendingBySuppliersChart"></div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="analytics-chart-card">
                    <div class="analytics-chart-title">Valor Pendente por Mês de Encomenda</div>
                    <div class="analytics-chart-box" id="pendingTrendChart"></div>
                </div>
            </div>
        </div>

        <div class="analytics-table-card">
            <div class="analytics-table-title">Listagem de Encomendas com Receção Pendente</div>
            <div class="table-responsive">
                <table id="datatable"
                       class="table table-bordered dt-responsive nowrap"
                       style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                    <thead>
                        <tr>
                            <th>Ln</th>
                            <th>Nº Encomenda</th>
                            <th>Fornecedor</th>
                            <th>Data</th>
                            <th class="text-end">Valor Encomendado</th>
                            <th class="text-end">Valor Recebido</th>
                            <th class="text-end">Valor Pendente</th>
                            <th class="text-end">% Satisfação</th>
                            <th class="text-center">Detalhe</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $key => $item)
                            <tr>
                                <td>{{ $key + 1 }}</td>
                                <td>{{ $item->pONumber }}</td>
                                <td>
                                    <span class="fw-semibold">{{ $item->supplierCode }}</span>
                                    <span class="text-muted"> - {{ optional($item->supplierLink)->name }}</span>
                                </td>
                                <td>{{ $item->pODate ? date('d/m/Y', strtotime($item->pODate)) : '-' }}</td>
                                <td class="text-end">{{ number_format($item->orderedValue, 2, ',', '.') }} €</td>
                                <td class="text-end">{{ number_format($item->receivedValue, 2, ',', '.') }} €</td>
                                <td class="text-end fw-semibold text-danger">{{ number_format($item->pendingValue, 2, ',', '.') }} €</td>
                                <td class="text-end">
                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                        <div class="pending-progress-wrap">
                                            <div class="pending-progress-bar" style="width: {{ $item->satisfactionPct }}%"></div>
                                        </div>
                                        <span>{{ number_format($item->satisfactionPct, 1, ',', '.') }}%</span>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <a href="{{ route('reports.pendingReceipts.detail', $item->id) }}"
                                       class="btn btn-info btn-sm"
                                       title="Ver produtos pendentes">
                                        <i class="fas fa-search"></i>
                                    </a>
                                </td>
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
    @vite('resources/css/reports/pending_receipts.css')
@endpush

@push('scripts')
    <script src="{{ asset('backend/assets/libs/apexcharts/apexcharts.min.js') }}"></script>
    @vite('resources/js/reports/pending_receipts_all.js')
@endpush
