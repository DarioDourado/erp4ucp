<!doctype html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Encomenda a Fornecedor {{ $purchaseOrder->pONumber }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #1f2937;
            margin: 24px;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        .header {
            margin-bottom: 18px;
            border-bottom: 2px solid #1d4ed8;
            padding-bottom: 12px;
        }

        .header-top {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .header-top td {
            border: none;
            padding: 0;
            vertical-align: middle;
        }

        .logo-cell {
            width: 170px;
        }

        .logo-cell img {
            max-width: 150px;
            max-height: 55px;
        }

        .title-cell {
            text-align: right;
        }

        .header h1 {
            font-size: 22px;
            margin-bottom: 6px;
        }

        .header-meta {
            width: 100%;
            border-collapse: collapse;
        }

        .header-meta td {
            width: 50%;
            vertical-align: top;
            padding: 4px 0;
        }

        .block {
            border: 1px solid #dbe4f0;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
        }

        .block-title {
            font-size: 13px;
            font-weight: bold;
            color: #1d4ed8;
            margin-bottom: 8px;
        }

        .label {
            color: #64748b;
            font-size: 11px;
        }

        .value {
            font-size: 12px;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #dbe4f0;
            padding: 7px 8px;
        }

        th {
            background: #eff6ff;
            color: #1e3a8a;
            font-size: 11px;
            text-align: left;
        }

        td.text-right,
        th.text-right {
            text-align: right;
        }

        .muted {
            color: #6b7280;
            font-size: 11px;
        }

        .footer-grid {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }

        .footer-grid td {
            vertical-align: top;
            border: none;
            padding: 0 8px 0 0;
        }

        .footer-grid td:last-child {
            padding-right: 0;
        }

        .totals-table td {
            border: 1px solid #dbe4f0;
        }

        .totals-table .grand-total td {
            background: #1d4ed8;
            color: #fff;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-top">
            <tr>
                <td class="logo-cell">
                    @if(!empty($logoBase64))
                        <img src="{{ $logoBase64 }}" alt="ERP4U">
                    @endif
                </td>
                <td class="title-cell">
                    <h1>Encomenda a Fornecedor</h1>
                </td>
            </tr>
        </table>
        <table class="header-meta">
            <tr>
                <td>
                    <div class="label">P.O. Nº</div>
                    <div class="value">{{ $purchaseOrder->pONumber }}</div>
                </td>
                <td>
                    <div class="label">Data</div>
                    <div class="value">{{ \Carbon\Carbon::parse($purchaseOrder->pODate)->format('d/m/Y') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="block">
        <div class="block-title">Fornecedor</div>
        <table class="header-meta">
            <tr>
                <td>
                    <div class="label">Código</div>
                    <div class="value">{{ $purchaseOrder->supplierCode }}</div>
                </td>
                <td>
                    <div class="label">Nome</div>
                    <div class="value">{{ optional($purchaseOrder->supplierLink)->name }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="label">NIF</div>
                    <div class="value">{{ optional($purchaseOrder->supplierLink)->nif ?: '-' }}</div>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>
                    <div class="label">Morada</div>
                    <div class="value">
                        {{ optional($purchaseOrder->supplierLink)->address1 ?: '-' }}
                        @if(!empty(optional($purchaseOrder->supplierLink)->address2))
                            <br>{{ optional($purchaseOrder->supplierLink)->address2 }}
                        @endif
                    </div>
                </td>
                <td>
                    <div class="label">Cód. Postal / Localidade</div>
                    <div class="value">
                        {{ optional($purchaseOrder->supplierLink)->postalCode ?: '-' }}
                        @if(!empty(optional($purchaseOrder->supplierLink)->town))
                            - {{ optional($purchaseOrder->supplierLink)->town }}
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="block">
        <div class="block-title">Detalhe da Encomenda</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">Ln</th>
                    <th>Artigo</th>
                    <th style="width: 60px;">Unid.</th>
                    <th style="width: 80px;" class="text-right">Qtd.</th>
                    <th style="width: 90px;" class="text-right">Pr. Unit.</th>
                    <th style="width: 70px;" class="text-right">IVA</th>
                    <th style="width: 100px;" class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($detailLines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>
                            <strong>{{ $line['productCode'] }}</strong>
                            @if(!empty($line['description']))
                                <span class="muted"> - {{ $line['description'] }}</span>
                            @endif
                        </td>
                        <td>{{ $line['productUnit'] }}</td>
                        <td class="text-right">{{ number_format($line['quantity'], 3, ',', '.') }}</td>
                        <td class="text-right">{{ number_format($line['unitPrice'], 2, ',', '.') }}</td>
                        <td class="text-right">{{ $line['taxRateCode'] }} ({{ number_format($line['taxRate'], 2, ',', '.') }}%)</td>
                        <td class="text-right">{{ number_format($line['lineNet'], 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <table class="footer-grid">
        <tr>
            <td style="width: 45%;">
                <div class="block" style="margin-bottom: 0;">
                    <div class="block-title">Observações</div>
                    <div>{{ $purchaseOrder->pOObservation ?: '-' }}</div>
                </div>
            </td>
            <td style="width: 25%;">
                <div class="block" style="margin-bottom: 0;">
                    <div class="block-title">Resumo IVA</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Cód.</th>
                                <th class="text-right">Taxa</th>
                                <th class="text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($taxSummary as $row)
                                <tr>
                                    <td>{{ $row['taxRateCode'] }}</td>
                                    <td class="text-right">{{ number_format($row['taxRate'], 2, ',', '.') }}%</td>
                                    <td class="text-right">{{ number_format($row['taxAmount'], 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                            <tr>
                                <td colspan="2"><strong>Total IVA</strong></td>
                                <td class="text-right"><strong>{{ number_format($taxSummary->sum('taxAmount'), 2, ',', '.') }}</strong></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </td>
            <td style="width: 30%;">
                <div class="block" style="margin-bottom: 0;">
                    <div class="block-title">Totais</div>
                    <table class="totals-table">
                        <tbody>
                            <tr>
                                <td>Total Líquido</td>
                                <td class="text-right">{{ number_format((float) $purchaseOrder->totalNet, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Total IVA</td>
                                <td class="text-right">{{ number_format((float) $purchaseOrder->totalTax, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Desc. Financeiro</td>
                                <td class="text-right">{{ number_format((float) $purchaseOrder->financialDiscount, 2, ',', '.') }}</td>
                            </tr>
                            <tr class="grand-total">
                                <td>Total Geral</td>
                                <td class="text-right">{{ number_format((float) $purchaseOrder->totalGross, 2, ',', '.') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
