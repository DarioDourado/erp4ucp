<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Entrada de Mercadoria {{ $receipt->gRNumber }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1e293b;
        }

        .header {
            margin-bottom: 14px;
        }

        .title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .meta-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .meta-grid td {
            padding: 4px 6px;
            border: 1px solid #dbe4f0;
            vertical-align: top;
        }

        .meta-label {
            color: #64748b;
            font-size: 10px;
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        .lines-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .lines-table th,
        .lines-table td {
            border: 1px solid #dbe4f0;
            padding: 6px;
        }

        .lines-table th {
            background: #eff6ff;
            text-align: left;
        }

        .text-right {
            text-align: right;
        }

        .summary {
            width: 45%;
            margin-left: auto;
            margin-top: 14px;
            border-collapse: collapse;
        }

        .summary td {
            border: 1px solid #dbe4f0;
            padding: 6px;
        }

        .summary tr:last-child td {
            font-weight: 700;
            background: #eff6ff;
        }

        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-ok {
            color: #166534;
            background: #dcfce7;
        }

        .status-cancelled {
            color: #991b1b;
            background: #fee2e2;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Entrada de Mercadoria</div>
        <span class="status {{ (int) $receipt->status === 1 ? 'status-ok' : 'status-cancelled' }}">
            {{ (int) $receipt->status === 1 ? 'Emitida' : 'Anulada' }}
        </span>
    </div>

    <table class="meta-grid">
        <tr>
            <td>
                <div class="meta-label">Nº Entrada</div>
                <div>{{ $receipt->gRNumber }}</div>
            </td>
            <td>
                <div class="meta-label">Data</div>
                <div>{{ $receipt->gRDate ? \Carbon\Carbon::parse($receipt->gRDate)->format('d/m/Y') : '-' }}</div>
            </td>
            <td>
                <div class="meta-label">Nº Encomenda</div>
                <div>{{ $receipt->purchaseOrderNumber ?: '-' }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="meta-label">Fornecedor</div>
                <div>{{ $receipt->supplierCode }} - {{ optional($receipt->supplierLink)->name }}</div>
            </td>
            <td>
                <div class="meta-label">Guia do Fornecedor</div>
                <div>{{ $receipt->supplierGuideNumber ?: '-' }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="3">
                <div class="meta-label">Observações</div>
                <div>{{ $receipt->gRObservation ?: '-' }}</div>
            </td>
        </tr>
    </table>

    <table class="lines-table">
        <thead>
            <tr>
                <th>Ln</th>
                <th>Artigo</th>
                <th>Unid.</th>
                <th class="text-right">Pedida</th>
                <th class="text-right">Entregue</th>
                <th class="text-right">Receber</th>
                <th class="text-right">Preço</th>
                <th class="text-right">IVA</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($detailLines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line['productCode'] }} - {{ $line['description'] }}</td>
                    <td>{{ $line['productUnit'] }}</td>
                    <td class="text-right">{{ number_format((float) $line['orderedQuantity'], 3, ',', '.') }}</td>
                    <td class="text-right">{{ number_format((float) $line['previousDeliveredQuantity'], 3, ',', '.') }}</td>
                    <td class="text-right">{{ number_format((float) $line['deliveryQuantity'], 3, ',', '.') }}</td>
                    <td class="text-right">{{ number_format((float) $line['unitPrice'], 2, ',', '.') }}</td>
                    <td class="text-right">{{ $line['taxRateCode'] }} ({{ number_format((float) $line['taxRate'], 2, ',', '.') }}%)</td>
                    <td class="text-right">{{ number_format((float) $line['lineNet'], 2, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td>Total Líquido</td>
            <td class="text-right">{{ number_format((float) $receipt->totalNet, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Total IVA</td>
            <td class="text-right">{{ number_format((float) $receipt->totalTax, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Total Geral</td>
            <td class="text-right">{{ number_format((float) $receipt->totalGross, 2, ',', '.') }}</td>
        </tr>
    </table>
</body>
</html>
