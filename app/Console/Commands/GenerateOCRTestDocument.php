<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

class GenerateOCRTestDocument extends Command
{
    protected $signature = 'ocr:generate-test-document';
    protected $description = 'Gera um documento PDF de teste para OCR';

    public function handle()
    {
        $this->info('Gerando documento de teste para OCR...');

        $data = [
            'supplier_name' => 'ACME Supply Co.',
            'supplier_address' => 'Rua Principal, 123',
            'supplier_city' => 'Amadora',
            'supplier_phone' => '212345678',
            'supplier_email' => 'info@acmesupply.com',
            'po_number' => 'PO-2026-001234',
            'invoice_date' => now()->format('d/m/Y'),
            'delivery_date' => now()->addDays(7)->format('d/m/Y'),
            'lines' => [
                [
                    'sku' => 'SKU-001',
                    'description' => 'Parafuso M8 Aço Inox',
                    'quantity' => 500,
                    'unit_price' => 0.25,
                    'total' => 125.00,
                ],
                [
                    'sku' => 'SKU-045',
                    'description' => 'Arruela Nylon',
                    'quantity' => 1000,
                    'unit_price' => 0.10,
                    'total' => 100.00,
                ],
                [
                    'sku' => 'SKU-089',
                    'description' => 'Mola Compressão',
                    'quantity' => 250,
                    'unit_price' => 0.50,
                    'total' => 125.00,
                ],
            ],
            'total_net' => 350.00,
            'total_tax' => 80.50,
            'total_gross' => 430.50,
        ];

        $html = $this->generateHTML($data);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        $path = public_path('documents/test_invoice.pdf');
        
        if (!is_dir(public_path('documents'))) {
            mkdir(public_path('documents'), 0755, true);
        }

        $pdf->save($path);

        $this->info('✓ Documento gerado com sucesso: ' . $path);
        $this->info('  Acesse: /documents/test_invoice.pdf');

        return 0;
    }

    private function generateHTML($data)
    {
        $linesHTML = '';
        foreach ($data['lines'] as $line) {
            $linesHTML .= <<<HTML
            <tr>
                <td style="padding: 8px; border: 1px solid #ddd;">{$line['sku']}</td>
                <td style="padding: 8px; border: 1px solid #ddd;">{$line['description']}</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">{$line['quantity']}</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">€ {$line['unit_price']}</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right;">€ {$line['total']}</td>
            </tr>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                * { margin: 0; padding: 0; }
                body { font-family: Arial, sans-serif; color: #333; }
                .container { max-width: 900px; margin: 0 auto; padding: 20px; }
                .header { margin-bottom: 30px; }
                .company-name { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
                .company-info { font-size: 12px; color: #666; }
                .divider { border-top: 2px solid #333; margin: 20px 0; }
                .document-title { font-size: 18px; font-weight: bold; text-align: center; margin: 20px 0; }
                .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
                .info-section { font-size: 13px; }
                .info-section label { font-weight: bold; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th { background-color: #f0f0f0; padding: 10px; text-align: left; font-weight: bold; border: 1px solid #ddd; font-size: 13px; }
                td { padding: 8px; border: 1px solid #ddd; font-size: 12px; }
                .totals { margin-left: auto; width: 300px; }
                .total-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #ddd; font-size: 13px; }
                .total-row.final { font-weight: bold; font-size: 14px; border-top: 2px solid #333; padding-top: 10px; }
                .footer { text-align: center; margin-top: 30px; font-size: 11px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <div class="company-name">{$data['supplier_name']}</div>
                    <div class="company-info">
                        {$data['supplier_address']}, {$data['supplier_city']}<br>
                        Tel: {$data['supplier_phone']} | Email: {$data['supplier_email']}
                    </div>
                </div>

                <div class="divider"></div>

                <div class="document-title">FATURA / GUIA DE REMESSA</div>

                <div class="info-grid">
                    <div class="info-section">
                        <label>Nº Encomenda:</label> {$data['po_number']}<br>
                        <label>Data de Emissão:</label> {$data['invoice_date']}<br>
                        <label>Data de Entrega Prevista:</label> {$data['delivery_date']}
                    </div>
                    <div class="info-section">
                        <label>Cliente:</label> ERP4U Company<br>
                        <label>Referência:</label> ORD-{$data['po_number']}<br>
                    </div>
                </div>

                <div class="divider"></div>

                <table>
                    <thead>
                        <tr>
                            <th>Artigo</th>
                            <th>Descrição</th>
                            <th style="text-align: center;">QTD</th>
                            <th style="text-align: right;">Preço Unit.</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$linesHTML}
                    </tbody>
                </table>

                <div class="totals">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span>€ {$data['total_net']}</span>
                    </div>
                    <div class="total-row">
                        <span>IVA 23%:</span>
                        <span>€ {$data['total_tax']}</span>
                    </div>
                    <div class="total-row final">
                        <span>TOTAL:</span>
                        <span>€ {$data['total_gross']}</span>
                    </div>
                </div>

                <div class="divider"></div>

                <div class="footer">
                    <p>Obrigado pela sua encomenda!</p>
                    <p style="margin-top: 10px;">Documento gerado automaticamente - Sem assinatura requerida</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }
}
