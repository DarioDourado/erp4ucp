<?php

namespace Database\Seeders;

use App\Models\PurchaseOrderC;
use App\Models\PurchaseOrderD;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class OCRTestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Verificar se já existe dados de teste
        $existingSupplier = Supplier::where('code', 999)->first();
        if ($existingSupplier) {
            echo "✓ Dados de teste já existem. Pulando seeder.\n";
            return;
        }

        // Criar Fornecedor de teste
        $supplier = Supplier::create([
            'code' => 999,
            'name' => 'ACME Supply Co.',
            'address1' => 'Rua Principal, 123',
            'town' => 'Amadora',
            'postalCode' => '2700-001',
            'created_by' => 1,
        ]);

        // Criar Encomenda de Compra de teste
        $purchaseOrder = PurchaseOrderC::create([
            'pONumber' => 'PO-2026-001234',
            'supplierCode' => $supplier->code,
            'pODate' => now(),
            'expectedDate' => now()->addDays(7),
            'created_by' => 1,
        ]);

        // Criar linhas da encomenda
        $poLines = [
            [
                'product' => 'SKU-001',
                'quantity' => 500,
                'price' => 0.25,
                'tax' => 'IVA23',
            ],
            [
                'product' => 'SKU-045',
                'quantity' => 1000,
                'price' => 0.10,
                'tax' => 'IVA23',
            ],
            [
                'product' => 'SKU-089',
                'quantity' => 250,
                'price' => 0.50,
                'tax' => 'IVA23',
            ],
        ];

        foreach ($poLines as $lineData) {
            PurchaseOrderD::create([
                'pONumber' => $purchaseOrder->pONumber,
                'productCode' => $lineData['product'],
                'quantity' => $lineData['quantity'],
                'deliveryQuantity' => 0,
                'unitPrice' => $lineData['price'],
                'taxRateCode' => $lineData['tax'],
                'created_by' => 1,
            ]);
        }

        echo "✓ Dados de teste criados com sucesso!\n";
        echo "  - Fornecedor: {$supplier->name} (Código: {$supplier->code})\n";
        echo "  - Encomenda: {$purchaseOrder->pONumber}\n";
        echo "  - Linhas: " . count($poLines) . "\n";
        echo "\nDados de teste disponíveis.\n";
    }
}
