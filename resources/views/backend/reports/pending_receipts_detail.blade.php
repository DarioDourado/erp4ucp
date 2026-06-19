@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">
                        Produtos Pendentes — Encomenda Nº {{ $order->pONumber }}
                    </h4>
                    <div class="page-title-right">
                        <a href="{{ route('reports.pendingReceipts') }}" class="btn btn-secondary btn-sm">
                            <i class="ri-arrow-left-line me-1"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <div class="pending-detail-info-label">Nº Encomenda</div>
                                <div class="pending-detail-info-value">{{ $order->pONumber }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="pending-detail-info-label">Fornecedor</div>
                                <div class="pending-detail-info-value">
                                    {{ $order->supplierCode }} — {{ optional($order->supplierLink)->name ?? '-' }}
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="pending-detail-info-label">Data da Encomenda</div>
                                <div class="pending-detail-info-value">
                                    {{ $order->pODate ? date('d/m/Y', strtotime($order->pODate)) : '-' }}
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="pending-detail-info-label">Satisfação Global</div>
                                <div class="d-flex align-items-center gap-2 mt-1">
                                    <div class="pending-progress-wrap flex-grow-1">
                                        <div class="pending-progress-bar" style="width: {{ $order->satisfactionPct }}%"></div>
                                    </div>
                                    <span class="fw-semibold">{{ number_format($order->satisfactionPct, 1, ',', '.') }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Total de Linhas</div>
                    <div class="analytics-kpi-value">{{ number_format($kpis['totalLines'], 0, ',', '.') }}</div>
                    <div class="analytics-kpi-note">
                        <span class="text-danger fw-semibold">{{ $kpis['pendingLines'] }}</span> com receção em falta.
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Valor Encomendado</div>
                    <div class="analytics-kpi-value">{{ number_format($kpis['totalOrdered'], 2, ',', '.') }} €</div>
                    <div class="analytics-kpi-note">Valor total desta encomenda.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card">
                    <div class="analytics-kpi-label">Valor Já Recebido</div>
                    <div class="analytics-kpi-value">{{ number_format($kpis['totalReceived'], 2, ',', '.') }} €</div>
                    <div class="analytics-kpi-note">Receção acumulada das entradas.</div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="analytics-kpi-card pending-kpi-highlight">
                    <div class="analytics-kpi-label">Valor Por Receber</div>
                    <div class="analytics-kpi-value text-danger">{{ number_format($kpis['totalPending'], 2, ',', '.') }} €</div>
                    <div class="analytics-kpi-note">Mercadoria ainda não entregue.</div>
                </div>
            </div>
        </div>

        <div class="analytics-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="analytics-table-title mb-0">Detalhe por Produto</div>
                <div class="d-flex gap-2">
                    <span class="pending-legend-badge badge-pending">Pendente</span>
                    <span class="pending-legend-badge badge-received">Recebido</span>
                </div>
            </div>
            <div class="table-responsive">
                <table id="datatable"
                       class="table table-bordered dt-responsive nowrap"
                       style="border-collapse: collapse; border-spacing: 0; width: 100%;">
                    <thead>
                        <tr>
                            <th>Ln</th>
                            <th>Estado</th>
                            <th>Código Produto</th>
                            <th>Família</th>
                            <th>Un.</th>
                            <th class="text-end">Qtd. Encomendada</th>
                            <th class="text-end">Qtd. Recebida</th>
                            <th class="text-end">Qtd. Pendente</th>
                            <th class="text-end">Preço Unit.</th>
                            <th class="text-end">Valor Pendente</th>
                            <th class="text-end">% Satisfação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lines as $key => $line)
                            <tr class="{{ $line['isPending'] ? 'pending-row' : 'received-row' }}">
                                <td>{{ $key + 1 }}</td>
                                <td>
                                    @if($line['isPending'])
                                        <span class="purchase-order-status-badge status-pending">Pendente</span>
                                    @else
                                        <span class="purchase-order-status-badge status-full">Recebido</span>
                                    @endif
                                </td>
                                <td class="fw-semibold">{{ $line['productCode'] }}</td>
                                <td>{{ $line['productFamily'] }}</td>
                                <td>{{ $line['productUnit'] }}</td>
                                <td class="text-end">{{ number_format($line['orderedQty'], 3, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($line['receivedQty'], 3, ',', '.') }}</td>
                                <td class="text-end {{ $line['isPending'] ? 'fw-semibold text-danger' : 'text-muted' }}">
                                    {{ number_format($line['pendingQty'], 3, ',', '.') }}
                                </td>
                                <td class="text-end">{{ number_format($line['unitPrice'], 2, ',', '.') }} €</td>
                                <td class="text-end {{ $line['isPending'] ? 'fw-semibold text-danger' : 'text-muted' }}">
                                    {{ number_format($line['pendingValue'], 2, ',', '.') }} €
                                </td>
                                <td class="text-end">
                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                        <div class="pending-progress-wrap">
                                            <div class="pending-progress-bar {{ $line['isPending'] ? '' : 'full' }}"
                                                 style="width: {{ $line['satisfactionPct'] }}%"></div>
                                        </div>
                                        <span>{{ number_format($line['satisfactionPct'], 1, ',', '.') }}%</span>
                                    </div>
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
    @vite('resources/js/reports/pending_receipts_detail.js')
@endpush
