@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <!-- start page title -->
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Encomendas a Fornecedores</h4>
                </div>
            </div>
        </div>
        <!-- end page title -->

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <a href="{{ route('purchaseOrder.add') }}"
                           class="btn btn-secondary btn-rounded waves-effect waves-light"
                           style="float: right;">
                            Adicionar EF
                        </a>

                        <br><br>

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <h4 class="card-title mb-0">Encomendas a Fornecedores</h4>

                            <div class="d-flex align-items-center gap-2">
                                <label for="purchaseOrderSatisfactionFilter" class="mb-0">Filtro</label>
                                <select id="purchaseOrderSatisfactionFilter" class="form-select form-select-sm">
                                    <option value="all">Todas</option>
                                    <option value="full">Totalmente satisfeitas</option>
                                    <option value="pending">Por satisfazer</option>
                                    <option value="none">Sem entradas</option>
                                </select>
                            </div>
                        </div>

                        <table id="datatable"
                               class="table table-bordered dt-responsive nowrap"
                               style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                            <thead>
                                <tr>
                                    <th>Ln</th>
                                    <th>Código do Fornecedor</th>
                                    <th>Nome do Fornecedor</th>
                                    <th>Nº Encomenda</th>
                                    <th>Data</th>
                                    <th>Observações</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach($allPOrderData as $key => $item)
                                    <tr data-satisfaction-filter="{{ $item->satisfactionFilter }}">
                                        <td>{{ $key + 1 }}</td>
                                        <td>{{ $item->supplierCode }}</td>
                                        <td>{{ optional($item->supplierLink)->name }}</td>
                                        <td>{{ $item->pONumber }}</td>
                                        <td>{{ date('d/m/Y', strtotime($item->pODate)) }}</td>
                                        
                                        <td>{{ $item->pOObservation }}</td>


                                        <td class="text-center purchase-order-action-cell">
                                            <a href="{{ route('purchaseOrder.pdf', $item->id) }}"
                                               class="btn btn-secondary btn-sm me-1"
                                               title="PDF"
                                               target="_blank">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>

                                            <a href="{{ route('purchaseOrder.edit', $item->id) }}"
                                               class="btn btn-info btn-sm me-1"
                                               title="Edit Data">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <a href="{{ route('purchaseOrder.delete', $item->id) }}"
                                               class="btn btn-danger btn-sm delete-btn"
                                               title="Delete Data">
                                                <i class="fas fa-trash-alt"></i>
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
    @vite('resources/css/purchaseOrder/purchaseOrderC_all.css')
@endpush

@push('scripts')
    @vite('resources/js/purchaseOrder/purchaseOrderC_all.js')
@endpush
