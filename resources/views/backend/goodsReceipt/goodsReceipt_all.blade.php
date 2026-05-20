@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Entradas de Mercadoria</h4>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <a href="{{ route('goodsReceipt.ocr') }}"
                           class="btn btn-primary btn-rounded waves-effect waves-light me-2"
                           style="float: right;">
                            <i class="fas fa-magic"></i> Entrada por OCR
                        </a>

                        <a href="{{ route('goodsReceipt.add') }}"
                           class="btn btn-secondary btn-rounded waves-effect waves-light"
                           style="float: right;">
                            Adicionar Entrada
                        </a>

                        <br><br>

                        <h4 class="card-title mb-3">Entradas de Mercadoria</h4>

                        <table id="datatable"
                               class="table table-bordered dt-responsive nowrap"
                               style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                            <thead>
                                <tr>
                                    <th>Ln</th>
                                    <th>Fornecedor</th>
                                    <th>Nº Entrada</th>
                                    <th>Nº Encomenda</th>
                                    <th>Data</th>
                                    <th>Guia Fornecedor</th>
                                    <th>Estado</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach($goodsReceipts as $key => $item)
                                    <tr>
                                        <td>{{ $key + 1 }}</td>
                                        <td>
                                            <span class="fw-semibold">{{ $item->supplierCode }}</span>
                                            <span class="text-muted"> - {{ optional($item->supplierLink)->name }}</span>
                                        </td>
                                        <td>{{ $item->gRNumber }}</td>
                                        <td>{{ $item->purchaseOrderNumber ?? '-' }}</td>
                                        <td>{{ $item->gRDate ? date('d/m/Y', strtotime($item->gRDate)) : '-' }}</td>
                                        <td>{{ $item->supplierGuideNumber ?: '-' }}</td>
                                        <td>
                                            @if((int) $item->status === 1)
                                                <span class="badge bg-success">Emitida</span>
                                            @else
                                                <span class="badge bg-danger">Anulada</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->totalGross, 2, ',', '.') }}</td>

                                        <td class="text-center goods-receipt-action-cell">
                                            <a href="{{ route('goodsReceipt.pdf', $item->id) }}"
                                               class="btn btn-secondary btn-sm me-1"
                                               title="Imprimir"
                                               target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>

                                            <a href="{{ route('goodsReceipt.edit', $item->id) }}"
                                               class="btn btn-info btn-sm me-1 {{ (int) $item->status === 0 ? 'disabled' : '' }}"
                                               title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <a href="{{ route('goodsReceipt.annul', $item->id) }}"
                                               class="btn btn-danger btn-sm delete-btn {{ (int) $item->status === 0 ? 'disabled' : '' }}"
                                               title="Anular">
                                                <i class="fas fa-ban"></i>
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

    </div>
</div>

@endsection

@push('styles')
    @vite('resources/css/goodsReceipt/goodsReceipt_all.css')
@endpush

@push('scripts')
    @vite('resources/js/goodsReceipt/goodsReceipt_all.js')
@endpush
