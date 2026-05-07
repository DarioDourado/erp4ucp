@extends('admin.admin_master')

@section('admin')

    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Manutenção de Artigos</h4>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">

                            <a href="{{ route('article.add') }}"
                                class="btn btn-secondary btn-rounded waves-effect waves-light" style="float: right;">
                                Adicionar Artigo
                            </a>

                            <br><br>

                            <h4 class="card-title">Lista de Artigos</h4>

                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <input type="text" id="articleSearch" class="form-control"
                                        placeholder="Pesquisa rápida por código, descrição, família, unidade, IVA...">
                                </div>
                            </div>

                            <table id="articleTable" class="table table-bordered dt-responsive nowrap"
                                style="border-collapse: collapse; border-spacing: 0; width: 100%;">

                                <thead>
                                    <tr>
                                        <th>Ln</th>
                                        <th>Código</th>
                                        <th>Descrição</th>
                                        <th>Imagem</th>
                                        <th>Unidade</th>
                                        <th>Família</th>
                                        <th>Taxa IVA</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    @foreach($articles as $key => $item)
                                        <tr>
                                            <td>{{ $key + 1 }}</td>
                                            <td>{{ $item->code }}</td>
                                            <td>{{ $item->description }}</td>
                                            <td>
                                                @if($item->image)
                                                    <img src="{{ asset($item->image) }}" alt="Imagem {{ $item->description }}"
                                                        style="width: 60px; height: 60px; object-fit: cover; border-radius: 6px;">
                                                @else
                                                    <span class="text-muted">Sem imagem</span>
                                                @endif
                                            </td>
                                            <td>{{ $item->unitMeasure ? $item->unitMeasure->unity : '' }}</td>
                                            <td>{{ $item->family ? $item->family->family : '' }}</td>
                                            <td>{{ $item->taxRate ? $item->taxRate->taxRate : '' }}%</td>
                                            <td>
                                                <a href="{{ route('article.edit', $item->id) }}" class="btn btn-info btn-sm"
                                                    title="Edit Data">
                                                    <i class="fas fa-edit"></i>
                                                </a>

                                                <a href="{{ route('article.delete', $item->id) }}"
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

@endsection

@push('datatable-scripts')
    <script>
        $(document).ready(function () {
            let table = null;

            if ($('#articleTable').length) {
                table = $('#articleTable').DataTable({
                    responsive: true,
                    paging: true,
                    searching: true,
                    ordering: true,
                    info: true,
                    lengthChange: true,
                    autoWidth: false,
                    dom: 'lrtip'
                });
            }

            $('#articleSearch').on('keyup', function () {
                if (table) {
                    table.search(this.value).draw();
                }
            });
        });
    </script>
@endpush
