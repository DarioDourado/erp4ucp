@extends('admin.admin_master')

@section('admin')

    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Manutenção de Fornecedores</h4>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">

                            <a href="{{ route('supplier.add') }}"
                                class="btn btn-secondary btn-rounded waves-effect waves-light" style="float: right;">
                                Adicionar Fornecedor
                            </a>

                            <br><br>

                            <h4 class="card-title">Manutenção de Fornecedores</h4>

                            <table id="datatable" class="table table-bordered dt-responsive nowrap"
                                style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                                <thead>
                                    <tr>
                                        <th>Ln</th>
                                        <th>Código</th>
                                        <th>Nome</th>
                                        <th>NIF</th>
                                        <th>Cod.Postal</th>
                                        <th>Localidade Postal</th>
                                        <th>Localidade</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach($suppliers as $key => $item)
                                        <tr>
                                            <td>{{ $key + 1 }}</td>
                                            <td>{{ $item->code }}</td>
                                            <td>{{ $item->name }}</td>
                                            <td>{{ $item->nif }}</td>
                                            <td>{{ $item->postalCode }}</td>
                                            <td>{{ $item->postalCodeLink ? $item->postalCodeLink->location : '' }}</td>
                                            <td>{{ $item->town }}</td>
                                            <td>
                                                <a href="{{ route('supplier.edit', $item->id) }}" class="btn btn-info btn-sm"
                                                    title="Edit Data">
                                                    <i class="fas fa-edit"></i>
                                                </a>

                                                <a href="{{ route('supplier.delete', $item->id) }}"
                                                    class="btn btn-danger btn-sm delete-btn" title="Delete Data">
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

    <script>
        $(document).ready(function () {
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
        });
    </script>

@endsection

@push('datatable-scripts')
@endpush