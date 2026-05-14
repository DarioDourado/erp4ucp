@extends('admin.admin_master')

@section('admin')

@php
    $isEdit = $mode === 'edit';
    $selectedSupplierName = optional($suppliers->firstWhere('code', $selectedSupplierCode))->name;
@endphp

<div class="page-content" id="goodsReceiptFormPage">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">{{ $isEdit ? 'Editar Entrada de Mercadoria' : 'Nova Entrada de Mercadoria' }}</h4>
                </div>
            </div>
        </div>

        <form method="post" action="{{ $isEdit ? route('goodsReceipt.update') : route('goodsReceipt.store') }}" id="goodsReceiptForm" novalidate>
            @csrf

            @if($isEdit)
                <input type="hidden" name="id" value="{{ $receipt->id }}">
            @endif

            <input type="hidden" name="purchaseOrderId" value="{{ old('purchaseOrderId', optional($selectedPurchaseOrder)->id) }}" id="purchaseOrderId">

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                                <h4 class="card-title mb-0">Cabeçalho da Entrada</h4>
                                <a href="{{ route('goodsReceipt.all') }}" class="btn btn-outline-secondary">
                                    Voltar
                                </a>
                            </div>

                            <div class="row">
                                <div class="col-lg-2">
                                    <div class="mb-3 form-group">
                                        <label for="gRNumber" class="form-label">Nº Entrada</label>
                                        <input id="gRNumber"
                                               name="gRNumber"
                                               type="number"
                                               min="1"
                                               class="form-control @error('gRNumber') is-invalid @enderror"
                                               value="{{ old('gRNumber', $nextGRNumber > 0 ? $nextGRNumber : '') }}"
                                               {{ $isEdit ? '' : 'readonly' }}>
                                        @error('gRNumber')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-2">
                                    <div class="mb-3 form-group">
                                        <label for="gRDate" class="form-label">Data</label>
                                        <input id="gRDate"
                                               name="gRDate"
                                               type="date"
                                               class="form-control @error('gRDate') is-invalid @enderror"
                                               value="{{ old('gRDate', $isEdit ? $receipt->gRDate : now()->format('Y-m-d')) }}">
                                        @error('gRDate')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-3">
                                    <div class="mb-3 form-group">
                                        <label for="supplierCode" class="form-label">Fornecedor</label>
                                        <select id="supplierCode"
                                                name="supplierCode"
                                                class="form-select select2 @error('supplierCode') is-invalid @enderror"
                                                data-placeholder="Selecione o fornecedor..."
                                                {{ $isEdit ? 'disabled' : '' }}>
                                            <option value=""></option>
                                            @foreach($suppliers as $supplier)
                                                <option value="{{ $supplier->code }}"
                                                        data-name="{{ $supplier->name }}"
                                                        {{ (string) old('supplierCode', $selectedSupplierCode) === (string) $supplier->code ? 'selected' : '' }}>
                                                    {{ $supplier->code }} - {{ $supplier->name }}
                                                </option>
                                            @endforeach
                                        </select>

                                        @if($isEdit)
                                            <input type="hidden" name="supplierCode" value="{{ old('supplierCode', $selectedSupplierCode) }}">
                                        @endif

                                        @error('supplierCode')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-2">
                                    <div class="mb-3 form-group">
                                        <label for="supplierName" class="form-label">Nome</label>
                                        <input id="supplierName"
                                               type="text"
                                               class="form-control bg-light"
                                               value="{{ old('supplier_name', $selectedSupplierName) }}"
                                               readonly>
                                    </div>
                                </div>

                                <div class="col-lg-3">
                                    <div class="mb-3 form-group">
                                        <label for="supplierGuideNumber" class="form-label">Guia de Fornecedor</label>
                                        <input id="supplierGuideNumber"
                                               name="supplierGuideNumber"
                                               type="text"
                                               class="form-control @error('supplierGuideNumber') is-invalid @enderror"
                                               maxlength="50"
                                               value="{{ old('supplierGuideNumber', $isEdit ? $receipt->supplierGuideNumber : $supplierGuideNumber) }}">
                                        @error('supplierGuideNumber')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row align-items-end">
                                <div class="col-lg-8">
                                    <div class="mb-3">
                                        <label class="form-label">Encomenda a Fornecedor</label>
                                        <input type="text"
                                               class="form-control bg-light"
                                               value="{{ $selectedPurchaseOrder ? ($selectedPurchaseOrder->pONumber . ' - ' . ($selectedPurchaseOrder->pODate ? date('d/m/Y', strtotime($selectedPurchaseOrder->pODate)) : 'Sem data')) : 'Nenhuma encomenda selecionada' }}"
                                               readonly>
                                    </div>
                                    @error('purchaseOrderId')
                                        <span class="invalid-feedback d-block">{{ $message }}</span>
                                    @enderror
                                </div>
                                <div class="col-lg-4">
                                    <div class="mb-3 d-grid">
                                        <a href="{{ route('goodsReceipt.selectPurchaseOrder', ['supplierCode' => old('supplierCode', $selectedSupplierCode)]) }}"
                                           id="btnSelectPO"
                                           class="btn btn-primary {{ $isEdit ? 'disabled' : '' }}">
                                            Selecionar Encomenda de Fornecedor
                                        </a>
                                    </div>
                                </div>
                            </div>

                            @if($errors->first('lines'))
                                <div class="alert alert-danger py-2 mb-0">
                                    {{ $errors->first('lines') }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">Linhas da Entrada</h4>

                            <div class="table-responsive order-lines-table-wrap">
                                <table class="table table-bordered align-middle mb-0" id="goodsReceiptLinesTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 60px;">Ln</th>
                                            <th style="min-width: 320px;">Artigo</th>
                                            <th style="width: 90px;">Unid.</th>
                                            <th style="width: 120px;">Stock</th>
                                            <th style="width: 110px;">Pedida</th>
                                            <th style="width: 110px;">Entregue</th>
                                            <th style="width: 110px;">Pendente</th>
                                            <th style="width: 120px;">Receber</th>
                                            <th style="width: 120px;">Preço</th>
                                            <th style="width: 120px;">IVA</th>
                                            <th style="width: 140px;">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($lineRows as $index => $line)
                                            <tr class="receipt-line"
                                                data-unit-price="{{ number_format((float) $line['unitPrice'], 2, '.', '') }}"
                                                data-tax-rate="{{ number_format((float) $line['taxRate'], 2, '.', '') }}"
                                                data-tax-code="{{ $line['taxRateCode'] }}">
                                                <td class="text-center">{{ $index + 1 }}</td>
                                                <td>
                                                    <span class="fw-semibold">{{ $line['productCode'] }}</span>
                                                    <span class="text-muted"> - {{ $line['productDescription'] }}</span>
                                                    <input type="hidden" name="lines[{{ $index }}][purchaseOrderDId]" value="{{ $line['purchaseOrderDId'] }}">
                                                </td>
                                                <td>{{ $line['productUnit'] }}</td>
                                                <td class="text-end">{{ number_format((float) $line['stockQuantity'], 3, ',', '.') }}</td>
                                                <td class="text-end">{{ number_format((float) $line['orderedQuantity'], 3, ',', '.') }}</td>
                                                <td class="text-end">{{ number_format((float) $line['previousDeliveredQuantity'], 3, ',', '.') }}</td>
                                                <td class="text-end">{{ number_format((float) $line['pendingQuantity'], 3, ',', '.') }}</td>
                                                <td>
                                                    <input type="number"
                                                           step="0.001"
                                                           min="0"
                                                           max="{{ number_format((float) $line['pendingQuantity'], 3, '.', '') }}"
                                                           name="lines[{{ $index }}][receiveQuantity]"
                                                           class="form-control form-control-sm text-end line-receive"
                                                           value="{{ old('lines.' . $index . '.receiveQuantity', number_format((float) $line['receiveQuantity'], 3, '.', '')) }}">
                                                </td>
                                                <td class="text-end">{{ number_format((float) $line['unitPrice'], 2, ',', '.') }}</td>
                                                <td class="text-end">
                                                    <span class="fw-semibold">{{ $line['taxRateCode'] }}</span>
                                                    <span class="text-muted">({{ number_format((float) $line['taxRate'], 2, ',', '.') }}%)</span>
                                                </td>
                                                <td class="text-end line-total-cell">0,00</td>
                                            </tr>
                                        @empty
                                            <tr id="emptyLineRow">
                                                <td colspan="11" class="text-center text-muted py-4">
                                                    Selecione uma encomenda para carregar as linhas pendentes.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h4 class="card-title mb-4">Rodapé e Totais</h4>

                            <div class="row g-4 align-items-stretch">
                                <div class="col-lg-4">
                                    <div class="card h-100 section-card">
                                        <div class="card-body footer-observation-panel">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h5 class="mb-0">Observações</h5>
                                            </div>
                                            <label for="gRObservation" class="form-label visually-hidden">Observações</label>
                                            <textarea id="gRObservation"
                                                      name="gRObservation"
                                                      class="form-control @error('gRObservation') is-invalid @enderror"
                                                      rows="8"
                                                      placeholder="Observações da entrada...">{{ old('gRObservation', $isEdit ? $receipt->gRObservation : '') }}</textarea>
                                            @error('gRObservation')
                                                <span class="invalid-feedback d-block">{{ $message }}</span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <div class="card h-100 section-card">
                                        <div class="card-body vat-summary-wrapper">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="mb-0">Resumo IVA</h5>
                                                <small class="text-muted">Agrupado por taxa</small>
                                            </div>

                                            <div class="table-responsive">
                                                <table class="table table-sm mb-0 vat-summary-table" id="taxSummaryTable">
                                                    <thead>
                                                        <tr>
                                                            <th>Cód. IVA</th>
                                                            <th>Taxa</th>
                                                            <th class="text-end">Valor IVA</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @forelse($taxSummary as $taxLine)
                                                            <tr>
                                                                <td>{{ $taxLine['taxRateCode'] }}</td>
                                                                <td>{{ number_format((float) $taxLine['taxRate'], 2, ',', '.') }}%</td>
                                                                <td class="text-end">{{ number_format((float) $taxLine['taxAmount'], 2, ',', '.') }}</td>
                                                            </tr>
                                                        @empty
                                                            <tr id="emptyTaxSummaryRow">
                                                                <td colspan="3" class="text-center text-muted py-3">
                                                                    Sem linhas para calcular IVA.
                                                                </td>
                                                            </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <div class="card h-100 section-card">
                                        <div class="card-body totals-card">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h5 class="mb-0">Totais</h5>
                                            </div>

                                            <div class="totals-panel-card">
                                                <div class="totals-panel">
                                                    <div class="total-box card">
                                                        <div class="card-body d-flex flex-column justify-content-between">
                                                            <span class="total-label d-block text-start">Total Líquido</span>
                                                            <span class="total-value d-block w-100 text-end fw-bold fs-3" id="totalNetDisplay">{{ number_format((float) $totals['totalNet'], 2, ',', '.') }}</span>
                                                        </div>
                                                    </div>
                                                    <div class="total-box card">
                                                        <div class="card-body d-flex flex-column justify-content-between">
                                                            <span class="total-label d-block text-start">Total IVA</span>
                                                            <span class="total-value d-block w-100 text-end fw-bold fs-3" id="totalTaxDisplay">{{ number_format((float) $totals['totalTax'], 2, ',', '.') }}</span>
                                                        </div>
                                                    </div>
                                                    <div class="total-box total-box-highlight card">
                                                        <div class="card-body d-flex flex-column justify-content-between">
                                                            <span class="total-label d-block text-start">Total Geral</span>
                                                            <span class="total-value d-block w-100 text-end fw-bold fs-3" id="totalGrossDisplay">{{ number_format((float) $totals['totalGross'], 2, ',', '.') }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 d-flex flex-wrap gap-2 justify-content-end">
                                <button type="submit" class="btn btn-primary" {{ !$selectedPurchaseOrder ? 'disabled' : '' }} id="btnSaveReceipt">
                                    {{ $isEdit ? 'Atualizar Entrada' : 'Registar Entrada' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

    </div>
</div>

@endsection

@push('styles')
    @vite('resources/css/goodsReceipt/goodsReceipt_add.css')
@endpush

@push('scripts')
    @vite('resources/js/goodsReceipt/goodsReceipt_add.js')
@endpush
