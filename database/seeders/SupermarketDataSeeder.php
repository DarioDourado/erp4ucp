<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupermarketDataSeeder extends Seeder
{
    private int $userId;

    public function run(): void
    {
        $this->userId = DB::table('users')->value('id') ?? 1;

        $this->seedFamilies();
        $this->seedUnits();
        $this->seedTaxRates();
        $this->seedSuppliers();
        $products = $this->seedProducts();
        $orders   = $this->seedPurchaseOrders($products);
        $this->seedGoodsReceipts($orders, $products);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Master Data
    // ─────────────────────────────────────────────────────────────────────────

    private function seedFamilies(): void
    {
        $families = [
            'Legumes', 'Carnes e Charcutaria', 'Peixe e Marisco',
            'Lacticínios', 'Padaria e Pastelaria', 'Mercearia',
            'Bebidas', 'Higiene e Beleza', 'Limpeza',
            'Congelados', 'Snacks e Doçaria', 'Cereais e Pequeno-Almoço',
        ];

        foreach ($families as $family) {
            DB::table('Family')->insertOrIgnore(['family' => $family]);
        }
    }

    private function seedUnits(): void
    {
        $units = ['UN', 'L', 'G', 'ML', 'CX', 'PCT'];

        foreach ($units as $unit) {
            DB::table('UnitMeasure')->insertOrIgnore(['unit' => $unit]);
        }
    }

    private function seedTaxRates(): void
    {
        DB::table('TaxRate')->insertOrIgnore([
            ['taxRateCode' => 13, 'taxRate' => 13],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Suppliers (20)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedSuppliers(): void
    {
        $now = Carbon::now();

        // [code, name, nif, address1, town, postalCode]
        $suppliers = [
            [101, 'Azeites do Alentejo, Lda',             '500123456', 'Rua do Azeite, 12',                    'Évora',               '7000-512'],
            [102, 'Lacticínios do Norte, SA',              '501234567', 'Av. do Leite, 45',                     'Braga',               '4700-320'],
            [103, 'Frescos do Sul, Lda',                   '502345678', 'Quinta dos Frescos, 3',                'Faro',                '8000-150'],
            [104, 'Cereais & Grãos, Lda',                  '503456789', 'Rua dos Cereais, 78',                  'Santarém',            '2000-234'],
            [105, 'Bebidas do Tejo, SA',                   '504567890', 'Parque Industrial, Lote 5',            'Almada',              '2800-410'],
            [106, 'Padaria Industrial Lisboa, Lda',        '505678901', 'Rua da Farinha, 23',                   'Lisboa',              '1700-200'],
            [107, 'Carnes Premium, Lda',                   '506789012', 'Zona Industrial, R/C',                 'Santarém',            '2000-456'],
            [108, 'Peixaria Nacional, SA',                 '507890123', 'Doca dos Pescadores, 1',               'Setúbal',             '2900-100'],
            [109, 'Higiene & Lar, Lda',                    '508901234', 'Rua da Limpeza, 56',                   'Porto',               '4000-388'],
            [110, 'Congelados do Atlântico, SA',           '509012345', 'Porto Marítimo, Armazém 8',            'Matosinhos',          '4450-210'],
            [111, 'Distribuidora do Minho, Lda',           '510123456', 'Rua do Comércio, 34',                  'Viana do Castelo',    '4900-355'],
            [112, 'Armazéns do Centro, SA',                '511234567', 'Av. Industrial, 100',                  'Coimbra',             '3000-520'],
            [113, 'Frutas do Ribatejo, Lda',               '512345678', 'Quinta dos Frutos, Km 3',              'Torres Novas',        '2350-680'],
            [114, 'Snacks & Doces, SA',                    '513456789', 'Parque Logístico Norte, Nave 4',       'Maia',                '4470-187'],
            [115, 'Importadora Ibérica, Lda',              '514567890', 'Rua das Importações, 2',               'Lisboa',              '1900-100'],
            [116, 'Alimentos do Douro, SA',                '515678901', 'Estrada Nacional 222, Km 10',          'Peso da Régua',       '5050-280'],
            [117, 'Eco Produtos Naturais, Lda',            '516789012', 'Vale Verde, Herdade 1',                'Beja',                '7800-540'],
            [118, 'Nacional Food, SA',                     '517890123', 'Zona Franca Industrial, Nave 12',      'Sintra',              '2710-301'],
            [119, 'Supridist Portugal, Lda',               '518901234', 'Av. da Distribuição, 88',              'Amadora',             '2700-512'],
            [120, 'Iberian Fresh, SA',                     '519012345', 'Mercado Abastecedor, Pavilhão 3',      'Vila Franca de Xira', '2600-180'],
        ];

        foreach ($suppliers as [$code, $name, $nif, $address1, $town, $postalCode]) {
            DB::table('Supplier')->insertOrIgnore([
                'code'       => $code,
                'name'       => $name,
                'nif'        => $nif,
                'address1'   => $address1,
                'address2'   => null,
                'town'       => $town,
                'postalCode' => $postalCode,
                'status'     => 1,
                'created_by' => $this->userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Products (107)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedProducts(): array
    {
        $now = Carbon::now();

        // [code, description, family, unit, taxRateCode]
        $products = [
            // Mercearia
            ['MRC001', 'Arroz Agulha 1KG',                    'Mercearia',                  'KG',  6],
            ['MRC002', 'Arroz Carolino 1KG',                  'Mercearia',                  'KG',  6],
            ['MRC003', 'Massa Esparguete 500G',               'Mercearia',                  'G',   6],
            ['MRC004', 'Massa Fusilli 500G',                  'Mercearia',                  'G',   6],
            ['MRC005', 'Massa Penne 500G',                    'Mercearia',                  'G',   6],
            ['MRC006', 'Feijão Encarnado 1KG',                'Mercearia',                  'KG',  6],
            ['MRC007', 'Grão de Bico 1KG',                    'Mercearia',                  'KG',  6],
            ['MRC008', 'Lentilhas 500G',                      'Mercearia',                  'G',   6],
            ['MRC009', 'Azeite Extra Virgem 750ML',           'Mercearia',                  'ML',  6],
            ['MRC010', 'Óleo Alimentar 1L',                   'Mercearia',                  'L',   6],
            ['MRC011', 'Vinagre de Vinho 750ML',              'Mercearia',                  'ML',  6],
            ['MRC012', 'Sal Refinado 1KG',                    'Mercearia',                  'KG',  6],
            ['MRC013', 'Açúcar Branco 1KG',                   'Mercearia',                  'KG',  6],
            ['MRC014', 'Farinha de Trigo T55 1KG',            'Mercearia',                  'KG',  6],
            ['MRC015', 'Café Torrado Moído 250G',             'Mercearia',                  'G',   6],
            ['MRC016', 'Mel de Flores 500G',                  'Mercearia',                  'G',   6],
            ['MRC017', 'Doce de Morango 375G',                'Mercearia',                  'G',   6],
            ['MRC018', 'Atum ao Natural 120G',                'Mercearia',                  'G',   6],
            ['MRC019', 'Sardinha em Tomate 120G',             'Mercearia',                  'G',   6],
            ['MRC020', 'Tomate Pelado 400G',                  'Mercearia',                  'G',   6],
            // Lacticínios
            ['LAC001', 'Leite UHT Meio Gordo 1L',             'Lacticínios',                'L',   6],
            ['LAC002', 'Leite UHT Magro 1L',                  'Lacticínios',                'L',   6],
            ['LAC003', 'Leite UHT Gordo 1L',                  'Lacticínios',                'L',   6],
            ['LAC004', 'Iogurte Natural 125G',                'Lacticínios',                'G',   6],
            ['LAC005', 'Iogurte Grego Natural 150G',          'Lacticínios',                'G',   6],
            ['LAC006', 'Manteiga com Sal 250G',               'Lacticínios',                'G',   6],
            ['LAC007', 'Queijo Flamengo Fatiado 150G',        'Lacticínios',                'G',   6],
            ['LAC008', 'Queijo da Serra 200G',                'Lacticínios',                'G',   6],
            ['LAC009', 'Natas para Culinária 200ML',          'Lacticínios',                'ML',  6],
            ['LAC010', 'Requeijão 250G',                      'Lacticínios',                'G',   6],
            // Frutas
            ['FRU001', 'Maçã Royal Gala 1KG',                 'Frutas',                     'KG',  6],
            ['FRU002', 'Pera Rocha 1KG',                      'Frutas',                     'KG',  6],
            ['FRU003', 'Laranja de Mesa 1KG',                 'Frutas',                     'KG',  6],
            ['FRU004', 'Banana 1KG',                          'Frutas',                     'KG',  6],
            ['FRU005', 'Morangos 250G',                       'Frutas',                     'G',   6],
            ['FRU006', 'Uvas Brancas Sem Grainha 500G',       'Frutas',                     'G',   6],
            ['FRU007', 'Kiwi Pack 4 UN',                      'Frutas',                     'UN',  6],
            ['FRU008', 'Limão 1KG',                           'Frutas',                     'KG',  6],
            // Legumes
            ['LEG001', 'Tomate Redondo 1KG',                  'Legumes',                    'KG',  6],
            ['LEG002', 'Alface Iceberg UN',                   'Legumes',                    'UN',  6],
            ['LEG003', 'Cenoura 1KG',                         'Legumes',                    'KG',  6],
            ['LEG004', 'Batata para Fritar 2KG',              'Legumes',                    'KG',  6],
            ['LEG005', 'Cebola 1KG',                          'Legumes',                    'KG',  6],
            ['LEG006', 'Alho 200G',                           'Legumes',                    'G',   6],
            ['LEG007', 'Pepino UN',                           'Legumes',                    'UN',  6],
            ['LEG008', 'Courgette 1KG',                       'Legumes',                    'KG',  6],
            ['LEG009', 'Brócolos 400G',                       'Legumes',                    'G',   6],
            ['LEG010', 'Espinafres Baby 300G',                'Legumes',                    'G',   6],
            // Carnes e Charcutaria
            ['CAR001', 'Frango Inteiro 1.5KG',                'Carnes e Charcutaria',       'KG',  6],
            ['CAR002', 'Peito de Frango 500G',                'Carnes e Charcutaria',       'G',   6],
            ['CAR003', 'Carne de Porco Picada 500G',          'Carnes e Charcutaria',       'G',   6],
            ['CAR004', 'Entrecosto de Porco 500G',            'Carnes e Charcutaria',       'G',   6],
            ['CAR005', 'Fiambre Cozido Fatiado 200G',         'Carnes e Charcutaria',       'G',   6],
            ['CAR006', 'Mortadela com Azeitonas 200G',        'Carnes e Charcutaria',       'G',   6],
            ['CAR007', 'Chouriço de Carne 200G',              'Carnes e Charcutaria',       'G',   6],
            ['CAR008', 'Bacon Fatiado 150G',                  'Carnes e Charcutaria',       'G',   6],
            ['CAR009', 'Salsicha de Frango 400G',             'Carnes e Charcutaria',       'G',   6],
            // Peixe e Marisco
            ['PEI001', 'Bacalhau Seco Demolhado 1KG',         'Peixe e Marisco',            'KG',  6],
            ['PEI002', 'Salmão Fresco Filete 500G',           'Peixe e Marisco',            'G',   6],
            ['PEI003', 'Camarão Cozido Descascado 500G',      'Peixe e Marisco',            'G',   6],
            ['PEI004', 'Filetes de Pescada 500G',             'Peixe e Marisco',            'G',   6],
            ['PEI005', 'Polvo Congelado 1KG',                 'Peixe e Marisco',            'KG',  6],
            // Padaria e Pastelaria
            ['PAD001', 'Pão de Forma Integral 500G',          'Padaria e Pastelaria',       'G',   6],
            ['PAD002', 'Pão de Forma Branco 500G',            'Padaria e Pastelaria',       'G',   6],
            ['PAD003', 'Croissant Pack 6 UN',                 'Padaria e Pastelaria',       'UN',  6],
            ['PAD004', 'Tostas Integrais 250G',               'Padaria e Pastelaria',       'G',   6],
            ['PAD005', 'Bolo de Arroz Pack 6',                'Padaria e Pastelaria',       'UN',  6],
            // Bebidas
            ['BEB001', 'Água Natural 6x1.5L',                 'Bebidas',                    'CX',  6],
            ['BEB002', 'Água com Gás 6x1.5L',                 'Bebidas',                    'CX',  6],
            ['BEB003', 'Refrigerante Cola 2L',                'Bebidas',                    'L',  23],
            ['BEB004', 'Sumo de Laranja 1L',                  'Bebidas',                    'L',   6],
            ['BEB005', 'Sumo de Maçã 1L',                     'Bebidas',                    'L',   6],
            ['BEB006', 'Cerveja Lata Pack 6x33CL',            'Bebidas',                    'CX', 23],
            ['BEB007', 'Vinho Tinto Regional 75CL',           'Bebidas',                    'ML', 23],
            ['BEB008', 'Vinho Branco Regional 75CL',          'Bebidas',                    'ML', 23],
            ['BEB009', 'Cidra 330ML',                         'Bebidas',                    'ML', 23],
            ['BEB010', 'Limonada 1.5L',                       'Bebidas',                    'L',  23],
            // Cereais e Pequeno-Almoço
            ['CER001', 'Flocos de Aveia 500G',                'Cereais e Pequeno-Almoço',   'G',   6],
            ['CER002', 'Cornflakes 375G',                     'Cereais e Pequeno-Almoço',   'G',   6],
            ['CER003', 'Muesli Frutos Secos 500G',            'Cereais e Pequeno-Almoço',   'G',   6],
            ['CER004', 'Granola com Frutas 400G',             'Cereais e Pequeno-Almoço',   'G',   6],
            // Snacks e Doçaria
            ['SNA001', 'Batatas Fritas Onduladas 150G',       'Snacks e Doçaria',           'G',  23],
            ['SNA002', 'Bolachas de Manteiga 200G',           'Snacks e Doçaria',           'G',  23],
            ['SNA003', 'Chocolate de Leite 100G',             'Snacks e Doçaria',           'G',  23],
            ['SNA004', 'Chocolates Sortidos 200G',            'Snacks e Doçaria',           'G',  23],
            ['SNA005', 'Gomas Coloridas 200G',                'Snacks e Doçaria',           'G',  23],
            ['SNA006', 'Pipocas Caramelo 100G',               'Snacks e Doçaria',           'G',  23],
            // Congelados
            ['CON001', 'Ervilhas Congeladas 1KG',             'Congelados',                 'KG',  6],
            ['CON002', 'Milho Congelado 1KG',                 'Congelados',                 'KG',  6],
            ['CON003', 'Batatas Fritas Congeladas 1KG',       'Congelados',                 'KG',  6],
            ['CON004', 'Pizza Margherita 400G',               'Congelados',                 'G',  23],
            ['CON005', 'Lasanha Bolonhesa 400G',              'Congelados',                 'G',  23],
            // Higiene e Beleza
            ['HIG001', 'Champô Cabelo Normal 400ML',          'Higiene e Beleza',           'ML', 23],
            ['HIG002', 'Gel de Banho 750ML',                  'Higiene e Beleza',           'ML', 23],
            ['HIG003', 'Pasta de Dentes 75ML',                'Higiene e Beleza',           'ML', 23],
            ['HIG004', 'Desodorizante Roll-On 50ML',          'Higiene e Beleza',           'ML', 23],
            ['HIG005', 'Loção Corporal Hidratante 400ML',     'Higiene e Beleza',           'ML', 23],
            // Limpeza
            ['LIM001', 'Detergente Roupa Líquido 1.5L',       'Limpeza',                    'L',  23],
            ['LIM002', 'Lava-Louça Concentrado 500ML',        'Limpeza',                    'ML', 23],
            ['LIM003', 'Limpador Multiusos 1L',               'Limpeza',                    'L',  23],
            ['LIM004', 'Papel Higiénico 12 Rolos',            'Limpeza',                    'CX', 23],
            ['LIM005', 'Guardanapos 100 UN',                  'Limpeza',                    'CX', 23],
            ['LIM006', 'Sacos de Lixo 20L 30 UN',            'Limpeza',                    'CX', 23],
            ['LIM007', 'Esponjas de Cozinha 5 UN',            'Limpeza',                    'CX', 23],
        ];

        foreach ($products as [$code, $description, $family, $unit, $taxRateCode]) {
            DB::table('Product')->insertOrIgnore([
                'code'        => $code,
                'description' => $description,
                'family'      => $family,
                'unit'        => $unit,
                'taxRateCode' => $taxRateCode,
                'image'       => 'upload/no_image.jpg',
                'created_by'  => $this->userId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Return map of code => [family, unit, taxRateCode]
        return collect($products)
            ->keyBy(0)
            ->map(fn ($p) => ['family' => $p[2], 'unit' => $p[3], 'taxRateCode' => $p[4]])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Purchase Orders (10)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedPurchaseOrders(array $products): array
    {
        $now = Carbon::now();

        // [pONumber, supplierCode, pODate, lines: [[productCode, qty, unitPrice]]]
        $orderDefinitions = [
            [1, 101, '2026-03-05', [
                ['MRC009', 50,  3.20],
                ['MRC010', 100, 1.10],
                ['MRC013', 80,  0.65],
                ['MRC012', 60,  0.45],
                ['MRC016', 40,  2.80],
            ]],
            [2, 102, '2026-03-12', [
                ['LAC001', 200, 0.68],
                ['LAC002', 150, 0.65],
                ['LAC003', 100, 0.72],
                ['LAC004', 300, 0.32],
                ['LAC006', 100, 1.35],
                ['LAC007', 80,  1.60],
            ]],
            [3, 103, '2026-03-20', [
                ['FRU001', 100, 0.85],
                ['FRU002', 80,  0.90],
                ['FRU003', 150, 0.60],
                ['LEG001', 200, 0.70],
                ['LEG003', 100, 0.55],
                ['LEG004', 120, 0.50],
            ]],
            [4, 104, '2026-04-02', [
                ['MRC001', 200, 0.62],
                ['MRC002', 150, 0.68],
                ['MRC003', 300, 0.55],
                ['MRC004', 200, 0.55],
                ['MRC006', 100, 0.90],
                ['MRC007', 80,  0.88],
            ]],
            [5, 105, '2026-04-10', [
                ['BEB001', 100, 2.40],
                ['BEB002', 80,  2.60],
                ['BEB003', 200, 1.20],
                ['BEB006', 150, 4.20],
                ['BEB007', 80,  2.80],
                ['BEB008', 80,  2.50],
            ]],
            [6, 107, '2026-04-18', [
                ['CAR001', 50,  3.50],
                ['CAR002', 100, 2.80],
                ['CAR003', 80,  2.20],
                ['CAR005', 120, 1.40],
                ['CAR007', 60,  1.60],
                ['CAR008', 80,  1.30],
            ]],
            [7, 109, '2026-05-05', [
                ['HIG001', 100, 1.80],
                ['HIG002', 150, 1.60],
                ['LIM001', 80,  3.20],
                ['LIM004', 200, 2.50],
                ['LIM002', 100, 0.90],
                ['LIM003', 80,  1.20],
            ]],
            [8, 108, '2026-05-15', [
                ['PEI001', 30,  6.80],
                ['PEI002', 50,  5.20],
                ['PEI003', 80,  4.50],
                ['PEI004', 60,  3.80],
                ['PEI005', 40,  5.50],
            ]],
            [9, 114, '2026-05-28', [
                ['CER001', 200, 0.90],
                ['CER002', 150, 1.20],
                ['SNA001', 300, 0.85],
                ['SNA002', 200, 0.95],
                ['SNA003', 200, 0.80],
                ['SNA005', 150, 0.75],
            ]],
            [10, 102, '2026-06-05', [
                ['LAC001', 300, 0.68],
                ['LAC002', 200, 0.65],
                ['LAC005', 150, 0.55],
                ['LAC009', 200, 0.90],
                ['LAC010', 100, 1.10],
                ['LAC008', 80,  2.40],
            ]],
        ];

        $result = [];

        foreach ($orderDefinitions as [$pONumber, $supplierCode, $pODate, $lines]) {
            $totalNet = 0;
            $totalTax = 0;
            $detailRows = [];

            foreach ($lines as [$productCode, $quantity, $unitPrice]) {
                $product = $products[$productCode];
                $taxRate  = $product['taxRateCode'];
                $lineNet  = round($quantity * $unitPrice, 2);
                $lineTax  = round($lineNet * ($taxRate / 100), 2);
                $totalNet += $lineNet;
                $totalTax += $lineTax;
                $detailRows[] = [$productCode, $quantity, $unitPrice, $product, $lineNet];
            }

            $totalGross = round($totalNet + $totalTax, 2);

            // Insert or get existing header
            $poExists = DB::table('PurchaseOrderC')->where('pONumber', $pONumber)->exists();
            if ($poExists) {
                $poId = DB::table('PurchaseOrderC')->where('pONumber', $pONumber)->value('id');
            } else {
                $poId = DB::table('PurchaseOrderC')->insertGetId([
                    'pONumber'          => $pONumber,
                    'supplierCode'      => $supplierCode,
                    'pODate'            => $pODate,
                    'pOObservation'     => null,
                    'financialDiscount' => 0,
                    'totalNet'          => round($totalNet, 2),
                    'totalTax'          => round($totalTax, 2),
                    'totalGross'        => $totalGross,
                    'status'            => 0,
                    'created_by'        => $this->userId,
                    'updated_by'        => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }

            // Insert detail lines
            $lineIds = [];
            foreach ($detailRows as [$productCode, $quantity, $unitPrice, $product]) {
                $existingId = DB::table('PurchaseOrderD')
                    ->where('pONumber', $pONumber)
                    ->where('productCode', $productCode)
                    ->value('id');

                if ($existingId) {
                    $lineIds[$productCode] = $existingId;
                } else {
                    $lineIds[$productCode] = DB::table('PurchaseOrderD')->insertGetId([
                        'pONumber'         => $pONumber,
                        'pODateDelivery'   => null,
                        'productCode'      => $productCode,
                        'productFamily'    => $product['family'],
                        'productUnit'      => $product['unit'],
                        'taxRateCode'      => $product['taxRateCode'],
                        'quantity'         => $quantity,
                        'deliveryQuantity' => 0,
                        'dicountPercent'   => 0,
                        'unitPrice'        => $unitPrice,
                        'sellingPrice'     => null,
                        'status'           => 0,
                        'created_by'       => $this->userId,
                        'updated_by'       => null,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
                }
            }

            $result[$pONumber] = [
                'id'           => $poId,
                'pONumber'     => $pONumber,
                'supplierCode' => $supplierCode,
                'pODate'       => $pODate,
                'lines'        => array_map(
                    fn ($row) => [
                        'id'          => $lineIds[$row[0]],
                        'productCode' => $row[0],
                        'quantity'    => $row[1],
                        'unitPrice'   => $row[2],
                        'product'     => $row[3],
                    ],
                    $detailRows
                ),
            ];
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Goods Receipts (7) + Stock Movements
    //  Orders 1-4: 100% recebidas | 5-6: 60% | 7: 40% | 8-10: pendentes
    // ─────────────────────────────────────────────────────────────────────────

    private function seedGoodsReceipts(array $orders, array $products): void
    {
        $now = Carbon::now();

        // [gRNumber, pONumber, gRDate, receiptPercent]
        $receiptDefs = [
            [1, 1, '2026-03-10', 1.0],
            [2, 2, '2026-03-17', 1.0],
            [3, 3, '2026-03-25', 1.0],
            [4, 4, '2026-04-07', 1.0],
            [5, 5, '2026-04-20', 0.6],
            [6, 6, '2026-04-25', 0.5],
            [7, 7, '2026-05-12', 0.4],
        ];

        // Running stock balance per product [code => qty]
        $stockBalance = [];

        foreach ($receiptDefs as [$gRNumber, $pONumber, $gRDate, $percent]) {
            if (DB::table('GoodsReceiptC')->where('gRNumber', $gRNumber)->exists()) {
                continue;
            }

            $order    = $orders[$pONumber];
            $totalNet = 0;
            $totalTax = 0;
            $rows     = [];

            foreach ($order['lines'] as $line) {
                $productCode = $line['productCode'];
                $product     = $line['product'];
                $unitPrice   = $line['unitPrice'];
                $orderedQty  = $line['quantity'];
                $receiveQty  = (int) round($orderedQty * $percent);
                $taxRate     = $product['taxRateCode'];
                $lineNet     = round($receiveQty * $unitPrice, 2);
                $lineTax     = round($lineNet * ($taxRate / 100), 2);
                $totalNet   += $lineNet;
                $totalTax   += $lineTax;

                $rows[] = [
                    'poLineId'    => $line['id'],
                    'productCode' => $productCode,
                    'productUnit' => $product['unit'],
                    'taxRateCode' => $taxRate,
                    'orderedQty'  => $orderedQty,
                    'receiveQty'  => $receiveQty,
                    'unitPrice'   => $unitPrice,
                    'lineNet'     => $lineNet,
                    'lineTax'     => $lineTax,
                ];
            }

            $grId = DB::table('GoodsReceiptC')->insertGetId([
                'gRNumber'            => $gRNumber,
                'supplierCode'        => $order['supplierCode'],
                'purchaseOrderId'     => $order['id'],
                'purchaseOrderNumber' => $pONumber,
                'supplierGuideNumber' => 'GF-' . str_pad($gRNumber, 5, '0', STR_PAD_LEFT),
                'gRDate'              => $gRDate,
                'gRObservation'       => null,
                'totalNet'            => round($totalNet, 2),
                'totalTax'            => round($totalTax, 2),
                'totalGross'          => round($totalNet + $totalTax, 2),
                'status'              => 1,
                'created_by'          => $this->userId,
                'updated_by'          => null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            foreach ($rows as $row) {
                $productCode = $row['productCode'];

                $grDId = DB::table('GoodsReceiptD')->insertGetId([
                    'goodsReceiptId'            => $grId,
                    'purchaseOrderDId'          => $row['poLineId'],
                    'productCode'               => $productCode,
                    'productUnit'               => $row['productUnit'],
                    'taxRateCode'               => $row['taxRateCode'],
                    'orderedQuantity'           => $row['orderedQty'],
                    'previousDeliveredQuantity' => 0,
                    'deliveryQuantity'          => $row['receiveQty'],
                    'pendingQuantity'           => max($row['orderedQty'] - $row['receiveQty'], 0),
                    'unitPrice'                 => $row['unitPrice'],
                    'lineNet'                   => $row['lineNet'],
                    'lineTax'                   => $row['lineTax'],
                    'status'                    => 1,
                    'created_by'                => $this->userId,
                    'updated_by'                => null,
                    'created_at'                => $now,
                    'updated_at'                => $now,
                ]);

                // Update PurchaseOrderD.deliveryQuantity
                DB::table('PurchaseOrderD')
                    ->where('id', $row['poLineId'])
                    ->increment('deliveryQuantity', $row['receiveQty']);

                // Calculate running average cost
                $prevBalance = $stockBalance[$productCode] ?? 0;
                $prevAvgCost = $stockBalance[$productCode . '_avg'] ?? $row['unitPrice'];
                $newBalance  = $prevBalance + $row['receiveQty'];
                $avgCost     = $newBalance > 0
                    ? round((($prevBalance * $prevAvgCost) + $row['lineNet']) / $newBalance, 4)
                    : $row['unitPrice'];

                $stockBalance[$productCode]          = $newBalance;
                $stockBalance[$productCode . '_avg'] = $avgCost;

                // Stock movement
                DB::table('StockMovement')->insert([
                    'productCode'       => $productCode,
                    'movementType'      => 'IN',
                    'sourceType'        => 'GoodsReceipt',
                    'sourceId'          => $grId,
                    'sourceLineId'      => $grDId,
                    'movementDate'      => $gRDate,
                    'quantity'          => $row['receiveQty'],
                    'unitCost'          => $row['unitPrice'],
                    'totalCost'         => $row['lineNet'],
                    'stockBalanceAfter' => $newBalance,
                    'averageCostAfter'  => $avgCost,
                    'created_by'        => $this->userId,
                    'updated_by'        => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            }
        }
    }
}
