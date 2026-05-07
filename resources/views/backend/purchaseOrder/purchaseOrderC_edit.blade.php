@extends('admin.admin_master')

@section('admin')

@php
    $selectedSupplierCode = old('supplierCode', $purchaseOrder->supplierCode);
    $selectedSupplierName = optional($suppliers->firstWhere('code', $selectedSupplierCode))->name;
@endphp

<div class="page-content"
     id="purchaseOrderFormPage"
    data-products='@json($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'
    data-initial-lines='@json($initialLines, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'>
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Editar Encomenda a Fornecedor</h4>
                </div>
            </div>
        </div>

        <form method="post" action="{{ route('purchaseOrder.update') }}" id="myForm" novalidate>
            @csrf
            <input type="hidden" name="id" value="{{ $purchaseOrder->id }}">
            <input type="hidden" name="original_pONumber" value="{{ $purchaseOrder->pONumber }}">

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                                <h4 class="card-title mb-0">Cabeçalho da Encomenda</h4>
                                <a href="{{ route('purchaseOrder.all') }}" class="btn btn-outline-secondary">
                                    Voltar
                                </a>
                            </div>

                            <div class="row">
                                <div class="col-lg-3">
                                    <div class="mb-3 form-group">
                                        <label for="pONumber" class="form-label">P.O. Nº</label>
                                        <input id="pONumber"
                                               name="pONumber"
                                               type="number"
                                               min="1"
                                               class="form-control @error('pONumber') is-invalid @enderror"
                                               value="{{ old('pONumber', $purchaseOrder->pONumber) }}">
                                        @error('pONumber')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-3">
                                    <div class="mb-3 form-group">
                                        <label for="pODate" class="form-label">Data</label>
                                        <input id="pODate"
                                               name="pODate"
                                               type="date"
                                               class="form-control @error('pODate') is-invalid @enderror"
                                               value="{{ old('pODate', $purchaseOrder->pODate) }}">
                                        @error('pODate')
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
                                                data-placeholder="Selecione o fornecedor...">
                                            <option value=""></option>
                                            @foreach($suppliers as $supplier)
                                                <option value="{{ $supplier->code }}"
                                                        data-name="{{ $supplier->name }}"
                                                        {{ (string) old('supplierCode', $purchaseOrder->supplierCode) === (string) $supplier->code ? 'selected' : '' }}>
                                                    {{ $supplier->code }} - {{ $supplier->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('supplierCode')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-3">
                                    <div class="mb-3 form-group">
                                        <label for="supplierName" class="form-label">Nome do Fornecedor</label>
                                        <input id="supplierName"
                                               type="text"
                                               class="form-control bg-light"
                                               value="{{ old('supplier_name', $selectedSupplierName) }}"
                                               readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                                <h4 class="card-title mb-0">Lançamento de Linhas</h4>
                                <span id="headerReadyBadge" class="badge bg-warning text-dark">
                                    Preencha primeiro o cabeçalho
                                </span>
                            </div>

                            <div class="row align-items-end">
                                <div class="col-lg-3">
                                    <div class="mb-3">
                                        <label for="familyPicker" class="form-label">Família</label>
                                        <select id="familyPicker"
                                                class="form-select select2"
                                                data-placeholder="Selecione a família...">
                                            <option value=""></option>
                                            @foreach($families as $family)
                                                <option value="{{ $family->family }}">
                                                    {{ $family->family }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-5">
                                    <div class="mb-3">
                                        <label for="productPicker" class="form-label">Produto</label>
                                        <select id="productPicker"
                                                class="form-select select2"
                                                data-placeholder="Selecione o artigo..."
                                                disabled>
                                            <option value=""></option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-2">
                                    <div class="mb-3">
                                        <label for="stockValue" class="form-label">Stock (Pic/Pic/Kg)</label>
                                        <input id="stockValue"
                                               type="text"
                                               class="form-control bg-light text-end"
                                               value="0"
                                               readonly>
                                    </div>
                                </div>

                                <div class="col-lg-2">
                                    <div class="mb-3 d-grid">
                                        <button type="button" id="btnAddLine" class="btn btn-success" disabled>
                                            + Add
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-info py-2 mb-3" id="headerInfoAlert">
                                O detalhe da encomenda só pode ser lançado depois de preencher P.O. Nº, Data e Fornecedor.
                            </div>

                            @if($errors->first('lines') || $errors->first('lines.*.productCode') || $errors->first('lines.*.quantity') || $errors->first('lines.*.unitPrice'))
                                <div class="alert alert-danger py-2">
                                    {{ $errors->first('lines') ?: ($errors->first('lines.*.productCode') ?: ($errors->first('lines.*.quantity') ?: $errors->first('lines.*.unitPrice'))) }}
                                </div>
                            @endif

                            <div class="table-responsive order-lines-table-wrap">
                                <table class="table table-bordered align-middle mb-0" id="orderLinesTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 60px;">Ln</th>
                                            <th style="min-width: 320px;">Artigo</th>
                                            <th style="width: 90px;">Unid.</th>
                                            <th style="width: 130px;">Stock</th>
                                            <th style="width: 130px;">PSC/LG</th>
                                            <th style="width: 140px;">Unit Price</th>
                                            <th style="width: 130px;">IVA</th>
                                            <th style="width: 140px;">Total Price</th>
                                            <th style="width: 90px;">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr id="emptyLineRow">
                                            <td colspan="9" class="text-center text-muted py-4">
                                                Ainda não existem linhas. Selecione família e artigo para adicionar.
                                            </td>
                                        </tr>
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
                                    <div class="footer-panel footer-observation-panel">
                                        <label for="pOObservation" class="form-label">Observações</label>
                                        <textarea id="pOObservation"
                                                  name="pOObservation"
                                                  class="form-control @error('pOObservation') is-invalid @enderror"
                                                  rows="8"
                                                  placeholder="Observações gerais da encomenda...">{{ old('pOObservation', $purchaseOrder->pOObservation) }}</textarea>
                                        @error('pOObservation')
                                            <span class="invalid-feedback d-block">{{ $message }}</span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <div class="vat-summary-wrapper">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h5 class="mb-0">Resumo IVA</h5>
                                            <small class="text-muted">Agrupado por código</small>
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
                                                    <tr id="emptyTaxSummaryRow">
                                                        <td colspan="3" class="text-center text-muted py-3">
                                                            Sem linhas para calcular IVA.
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-4">
                                    <div class="totals-card">
                                        <div class="mb-3 form-group">
                                            <label for="financialDiscount" class="form-label">Desconto Financeiro</label>
                                            <input id="financialDiscount"
                                                   name="financialDiscount"
                                                   type="number"
                                                   step="0.01"
                                                   min="0"
                                                   class="form-control text-end @error('financialDiscount') is-invalid @enderror"
                                                   value="{{ old('financialDiscount', $purchaseOrder->financialDiscount ?? 0) }}">
                                            @error('financialDiscount')
                                                <span class="invalid-feedback d-block">{{ $message }}</span>
                                            @enderror
                                        </div>

                                        <div class="totals-panel">
                                            <div class="total-box">
                                                <span class="total-label">Total Líquido</span>
                                                <span class="total-value" id="totalNetDisplay">0,00</span>
                                            </div>
                                            <div class="total-box">
                                                <span class="total-label">Total IVA</span>
                                                <span class="total-value" id="totalTaxDisplay">0,00</span>
                                            </div>
                                            <div class="total-box total-box-highlight">
                                                <span class="total-label">Total Geral</span>
                                                <span class="total-value" id="totalGrossDisplay">0,00</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 d-flex flex-wrap gap-2 justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    Atualizar Encomenda
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
    @vite('resources/css/purchaseOrder/purchaseOrderC_edit.css')
@endpush

@push('scripts')
    @vite('resources/js/purchaseOrder/purchaseOrderC_edit.js')
@endpush
