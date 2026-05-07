@extends('admin.admin_master')

@section('admin')

<div class="page-content">
    <div class="container-fluid">

        <!-- start page title -->
        <div class="row">
            <div class="col-12">
                <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                    <h4 class="mb-sm-0">Manutenção de Artigos</h4>
                </div>
            </div>
        </div>
        <!-- end page title -->

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">

                        <a href="{{ route('product.add') }}"
                           class="btn btn-secondary btn-rounded waves-effect waves-light"
                           style="float: right;">
                            Adicionar Artigo
                        </a>

                        <br><br>

                        <h4 class="card-title">Manutenção de Artigos</h4>

                        <table id="datatable"
                               class="table table-bordered dt-responsive nowrap"
                               style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                            <thead>
                                <tr>
                                    <th>Ln</th>
                                    <th>Código</th>
                                    <th>Descrição</th>
                                    <th>Imagem</th>
                                    <th>Unid.Medida</th>
                                    <th>Familia</th>
                                    <th>Taxa de IVA(%)</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach($products as $key => $item)
                                    <tr>
                                        <td>{{ $key + 1 }}</td>
                                        <td>{{ $item->code }}</td>
                                        <td>{{ $item->description }}</td>

                                        <td>
                                            <img src="{{ !empty($item->image) ? asset($item->image) : asset('upload/no_image.jpg') }}"
                                                style="width: 60px; height: 50px;">
                                        </td>
                                        
                                        <td>{{ $item->unit }}</td>
                                        <td>{{ $item->family }}</td>
                                        <td>{{ $item['codeRateLink']['taxRate'] }}</td>

                                        <td class="text-center">
                                            <a href="{{ route('product.edit', $item->id) }}"
                                               class="btn btn-info btn-sm me-1"
                                               title="Edit Data">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <a href="{{ route('product.delete', $item->id) }}"
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

@push('datatable-scripts')
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
@endpush
