@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Unidades de Medida</h4>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <a href="{{ route('unitMeasure.add') }}"
                           class="btn btn-secondary btn-rounded waves-effect waves-light"
                           style="float: right;">
                            Adicionar Unidade
                        </a>

                        <br><br>

                        <h4 class="card-title">Unidades de Medida — Todos os Registos</h4>

                        <table id="datatable"
                               class="table table-bordered dt-responsive nowrap"
                               style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                            <thead>
                                <tr>
                                    <th>Ln</th>
                                    <th>Unidade</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach($unitMeasures as $key => $item)
                                    <tr>
                                        <td>{{ $key + 1 }}</td>
                                        <td>{{ $item->unity }}</td>
                                        <td>
                                            <a href="{{ route('unitMeasure.edit', $item->id) }}"
                                               class="btn btn-info btn-sm"
                                               title="Edit Data">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <a href="{{ route('unitMeasure.delete', $item->id) }}"
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
