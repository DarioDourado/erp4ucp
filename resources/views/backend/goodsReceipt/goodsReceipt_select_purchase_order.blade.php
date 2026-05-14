@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Selecionar Encomenda a Fornecedor</h4>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <h4 class="card-title mb-0">Encomendas Pendentes</h4>
                            <a href="{{ route('goodsReceipt.add', ['supplierCode' => $supplierCode, 'supplierGuideNumber' => $supplierGuideNumber]) }}" class="btn btn-outline-secondary">
                                Voltar à Entrada
                            </a>
                        </div>

                        @if($supplierCode <= 0)
                            <div class="alert alert-info mb-3">
                                Selecione primeiro o fornecedor no ecrã da entrada de mercadoria.
                            </div>
                        @endif

                        <table id="datatable"
                               class="table table-bordered dt-responsive nowrap"
                               style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                            <thead>
                                <tr>
                                    <th>Ln</th>
                                    <th>Nº Encomenda</th>
                                    <th>Data</th>
                                    <th>Observações</th>
                                    <th>Linhas Pendentes</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach($purchaseOrders as $key => $item)
                                    <tr>
                                        <td>{{ $key + 1 }}</td>
                                        <td>{{ $item->pONumber }}</td>
                                        <td>{{ $item->pODate ? date('d/m/Y', strtotime($item->pODate)) : '-' }}</td>
                                        <td>{{ $item->pOObservation ?: '-' }}</td>
                                        <td>{{ $item->pendingLinesCount }}</td>
                                        <td class="text-center">
                                            <a href="{{ route('goodsReceipt.add', ['supplierCode' => $supplierCode, 'purchaseOrderId' => $item->id, 'supplierGuideNumber' => $supplierGuideNumber]) }}"
                                               class="btn btn-primary btn-sm"
                                               title="Selecionar">
                                                <i class="fas fa-check"></i>
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

@push('scripts')
    @vite('resources/js/goodsReceipt/goodsReceipt_select_purchase_order.js')
@endpush
