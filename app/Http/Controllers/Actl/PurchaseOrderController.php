<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Supplier;
use App\Models\Product;
use App\Models\Family;
use App\Models\TaxRate;
use App\Models\UnitMeasure;
use App\Models\PurchaseOrderC;
use App\Models\PurchaseOrderD;
use App\Models\StockMovement;

use App\Services\OcrService;
use Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use thiagoalessio\TesseractOCR\TesseractOCR;

class PurchaseOrderController extends Controller
{
    public function PurchaseOrderAll()
    {
        $allPOrderData = PurchaseOrderC::with(['supplierLink', 'detailLines'])
            ->orderBy('pODate', 'DESC')
            ->orderBy('pONumber', 'DESC')
            ->get()
            ->map(fn ($purchaseOrder) => $this->decoratePurchaseOrderSummary($purchaseOrder));

        return view('backend.purchaseOrder.purchaseOrderC_all', compact('allPOrderData'));
    }

    public function PurchaseOrderAnalytics(Request $request)
    {
        $statusFilter = $request->query('status', 'all');

        $allPurchaseOrders = PurchaseOrderC::with(['supplierLink', 'detailLines'])
            ->orderBy('pODate', 'DESC')
            ->orderBy('pONumber', 'DESC')
            ->get()
            ->map(fn ($purchaseOrder) => $this->decoratePurchaseOrderSummary($purchaseOrder));

        $filteredPurchaseOrders = $this->filterPurchaseOrdersBySatisfaction($allPurchaseOrders, $statusFilter)->values();
        $analyticsData = $this->buildPurchaseOrderAnalyticsData($allPurchaseOrders, $filteredPurchaseOrders);

        return view('backend.purchaseOrder.purchaseOrder_analytics', compact(
            'allPurchaseOrders',
            'filteredPurchaseOrders',
            'statusFilter',
            'analyticsData'
        ));
    }

    public function PurchaseOrderAdd(Request $request)
    {
        $suppliers = $this->getSuppliers();
        $families = $this->getFamilies();
        $products = $this->getProductsForForm();
        $nextPONumber = ((int) PurchaseOrderC::max('pONumber')) + 1;

        // --- OCR pre-fill: verificar se há dados de OCR em sessão ---
        $ocrData = session('ocr_purchase_order_data');
        $ocrSupplierCode = $request->query('supplier_code');
        $initialLines = $this->normalizeLinesForForm(collect(old('lines', [])));

        if ($ocrData && empty(old())) {
            $ocrSupplierCode = $ocrData['supplier']['code'] ?? $ocrSupplierCode;
            $initialLines = collect($ocrData['lines'] ?? [])
                ->map(function ($line) {
                    return [
                        'productCode' => (string) ($line['productCode'] ?? ''),
                        'quantity' => (float) ($line['quantity'] ?? 1),
                        'unitPrice' => (float) ($line['unitPrice'] ?? 0),
                    ];
                })
                ->values();
        }

        return view('backend.purchaseOrder.purchaseOrderC_add', compact(
            'suppliers',
            'families',
            'products',
            'nextPONumber',
            'initialLines',
            'ocrSupplierCode'
        ));
    }

    public function PurchaseOrderStore(Request $request)
    {
        $validated = $this->validatePurchaseOrderRequest($request);
        $calculated = $this->calculatePurchaseOrderPayload($validated);
        $now = now();

        DB::transaction(function () use ($validated, $calculated, $now) {
            PurchaseOrderC::create([
                'pONumber' => $validated['pONumber'],
                'supplierCode' => $validated['supplierCode'],
                'pODate' => $validated['pODate'],
                'pOObservation' => $validated['pOObservation'] ?? null,
                'financialDiscount' => $calculated['financialDiscount'],
                'totalNet' => $calculated['totalNet'],
                'totalTax' => $calculated['totalTax'],
                'totalGross' => $calculated['totalGross'],
                'status' => 0,
                'created_by' => Auth::id(),
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            PurchaseOrderD::insert($calculated['detailRows']);
        });

        return redirect()->route('purchaseOrder.all')->with([
            'message' => 'Encomenda a fornecedor criada com sucesso.',
            'alert-type' => 'success',
        ]);
    }

    public function PurchaseOrderEdit($id)
    {
        $purchaseOrder = PurchaseOrderC::with(['supplierLink', 'detailLines'])->findOrFail($id);
        $suppliers = $this->getSuppliers();
        $families = $this->getFamilies();
        $products = $this->getProductsForForm();
        $nextPONumber = $purchaseOrder->pONumber;

        $initialLines = old('lines')
            ? $this->normalizeLinesForForm(collect(old('lines', [])))
            : $purchaseOrder->detailLines
                ->sortBy('id')
                ->values()
                ->map(function ($line) {
                    return [
                        'productCode' => (string) ($line->productCode ?? ''),
                        'quantity' => (float) ($line->quantity ?? 1),
                        'unitPrice' => (float) ($line->unitPrice ?? 0),
                    ];
                })
                ->values();

        return view('backend.purchaseOrder.purchaseOrderC_edit', compact(
            'purchaseOrder',
            'suppliers',
            'families',
            'products',
            'nextPONumber',
            'initialLines'
        ));
    }

    public function PurchaseOrderUpdate(Request $request)
    {
        $purchaseOrder = PurchaseOrderC::findOrFail($request->id);

        $validated = $this->validatePurchaseOrderRequest($request, $purchaseOrder->id);
        $calculated = $this->calculatePurchaseOrderPayload($validated);
        $originalPONumber = (int) $request->input('original_pONumber', $purchaseOrder->pONumber);
        $now = now();

        DB::transaction(function () use ($purchaseOrder, $validated, $calculated, $originalPONumber, $now) {
            PurchaseOrderD::where('pONumber', $originalPONumber)->delete();

            $purchaseOrder->update([
                'pONumber' => $validated['pONumber'],
                'supplierCode' => $validated['supplierCode'],
                'pODate' => $validated['pODate'],
                'pOObservation' => $validated['pOObservation'] ?? null,
                'financialDiscount' => $calculated['financialDiscount'],
                'totalNet' => $calculated['totalNet'],
                'totalTax' => $calculated['totalTax'],
                'totalGross' => $calculated['totalGross'],
                'updated_by' => Auth::id(),
                'updated_at' => $now,
            ]);

            PurchaseOrderD::insert($calculated['detailRows']);
        });

        return redirect()->route('purchaseOrder.all')->with([
            'message' => 'Encomenda a fornecedor atualizada com sucesso.',
            'alert-type' => 'success',
        ]);
    }

    public function PurchaseOrderDelete($id)
    {
        $purchaseOrder = PurchaseOrderC::findOrFail($id);

        DB::transaction(function () use ($purchaseOrder) {
            PurchaseOrderD::where('pONumber', $purchaseOrder->pONumber)->delete();
            $purchaseOrder->delete();
        });

        return redirect()->route('purchaseOrder.all')->with([
            'message' => 'Encomenda a fornecedor eliminada com sucesso.',
            'alert-type' => 'success',
        ]);
    }

    public function PurchaseOrderPdf($id)
    {
        $purchaseOrder = PurchaseOrderC::with(['supplierLink', 'detailLines'])
            ->findOrFail($id);

        $taxRates = TaxRate::all()->keyBy('taxRateCode');
        $productDescriptions = Product::whereIn('code', $purchaseOrder->detailLines->pluck('productCode')->unique()->all())
            ->get(['code', 'description'])
            ->keyBy('code');

        $detailLines = $purchaseOrder->detailLines
            ->sortBy('id')
            ->values()
            ->map(function ($line) use ($taxRates, $productDescriptions) {
                $taxRateRow = $taxRates->get($line->taxRateCode);
                $taxRate = (float) optional($taxRateRow)->taxRate;
                $lineNet = round((float) $line->quantity * (float) $line->unitPrice, 2);
                $lineTax = round($lineNet * ($taxRate / 100), 2);

                return [
                    'productCode' => $line->productCode,
                    'description' => optional($productDescriptions->get($line->productCode))->description,
                    'productUnit' => $line->productUnit,
                    'quantity' => (float) $line->quantity,
                    'unitPrice' => (float) $line->unitPrice,
                    'taxRateCode' => $line->taxRateCode,
                    'taxRate' => $taxRate,
                    'lineNet' => $lineNet,
                    'lineTax' => $lineTax,
                ];
            });

        $taxSummary = $detailLines
            ->groupBy('taxRateCode')
            ->map(function ($lines, $taxRateCode) {
                $firstLine = $lines->first();

                return [
                    'taxRateCode' => $taxRateCode,
                    'taxRate' => (float) ($firstLine['taxRate'] ?? 0),
                    'taxAmount' => round($lines->sum('lineTax'), 2),
                ];
            })
            ->sortBy('taxRateCode')
            ->values();

        $logoBase64 = null;
        $logoPath = public_path('logotipos/erp4u.png');

        if (file_exists($logoPath)) {
            $logoContents = file_get_contents($logoPath);
            $logoExtension = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $logoMime = $logoExtension === 'png' ? 'image/png' : 'image/jpeg';

            $logoBase64 = 'data:' . $logoMime . ';base64,' . base64_encode($logoContents);
        }

        $pdf = Pdf::loadView('backend.purchaseOrder.purchaseOrderC_pdf', [
            'purchaseOrder' => $purchaseOrder,
            'detailLines' => $detailLines,
            'taxSummary' => $taxSummary,
            'logoBase64' => $logoBase64,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('encomenda-fornecedor-' . $purchaseOrder->pONumber . '.pdf');
    }

    private function getSuppliers()
    {
        return Supplier::orderBy('code')->get(['code', 'name']);
    }

    private function getFamilies()
    {
        return Family::orderBy('family')->get(['family']);
    }

    private function getProductsForForm()
    {
        $products = Product::with('codeRateLink')
            ->orderBy('family')
            ->orderBy('description')
            ->get();
        $stockMap = $this->getStockSummaryByProduct($products->pluck('code')->all());

        return $products
            ->map(function ($product) use ($stockMap) {
                $stockData = $stockMap[(string) $product->code] ?? ['stockQuantity' => 0, 'averageCost' => 0];

                return [
                    'code' => (string) $product->code,
                    'description' => (string) $product->description,
                    'family' => (string) $product->family,
                    'unit' => (string) ($product->unit ?? ''),
                    'taxRateCode' => (string) ($product->taxRateCode ?? ''),
                    'taxRate' => (float) optional($product->codeRateLink)->taxRate,
                    'stockQuantity' => (float) ($stockData['stockQuantity'] ?? 0),
                ];
            })
            ->values();
    }

    private function normalizeLinesForForm($lines)
    {
        return $lines->values()
            ->map(function ($line) {
                return [
                    'productCode' => (string) ($line['productCode'] ?? ''),
                    'quantity' => (float) ($line['quantity'] ?? 1),
                    'unitPrice' => (float) ($line['unitPrice'] ?? 0),
                ];
            })
            ->values();
    }

    private function validatePurchaseOrderRequest(Request $request, $ignoreId = null)
    {
        $purchaseOrderTable = (new PurchaseOrderC())->getTable();
        $supplierTable = (new Supplier())->getTable();
        $productTable = (new Product())->getTable();

        $poNumberRule = Rule::unique($purchaseOrderTable, 'pONumber');

        if ($ignoreId) {
            $poNumberRule->ignore($ignoreId);
        }

        return $request->validate([
            'id' => ['nullable', 'integer', Rule::exists($purchaseOrderTable, 'id')],
            'pONumber' => ['required', 'integer', $poNumberRule],
            'pODate' => ['required', 'date'],
            'supplierCode' => ['required', Rule::exists($supplierTable, 'code')],
            'pOObservation' => ['nullable', 'string'],
            'financialDiscount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.productCode' => ['required', Rule::exists($productTable, 'code')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitPrice' => ['required', 'numeric', 'min:0'],
        ], [
            'id.exists' => 'A encomenda indicada não existe.',
            'pONumber.required' => 'Introduza o n.º da encomenda.',
            'pONumber.integer' => 'O n.º da encomenda tem de ser numérico.',
            'pONumber.unique' => 'Já existe uma encomenda com esse n.º.',
            'pODate.required' => 'Introduza a data da encomenda.',
            'pODate.date' => 'A data da encomenda é inválida.',
            'supplierCode.required' => 'Selecione o fornecedor.',
            'supplierCode.exists' => 'O fornecedor selecionado não existe.',
            'financialDiscount.numeric' => 'O desconto financeiro tem de ser numérico.',
            'financialDiscount.min' => 'O desconto financeiro não pode ser negativo.',
            'lines.required' => 'Adicione pelo menos uma linha à encomenda.',
            'lines.array' => 'O detalhe da encomenda é inválido.',
            'lines.min' => 'Adicione pelo menos uma linha à encomenda.',
            'lines.*.productCode.required' => 'Existe uma linha sem artigo selecionado.',
            'lines.*.productCode.exists' => 'Existe um artigo inválido no detalhe.',
            'lines.*.quantity.required' => 'Preencha a quantidade em todas as linhas.',
            'lines.*.quantity.numeric' => 'A quantidade tem de ser numérica.',
            'lines.*.quantity.gt' => 'A quantidade tem de ser superior a zero.',
            'lines.*.unitPrice.required' => 'Preencha o preço unitário em todas as linhas.',
            'lines.*.unitPrice.numeric' => 'O preço unitário tem de ser numérico.',
            'lines.*.unitPrice.min' => 'O preço unitário não pode ser negativo.',
        ]);
    }

    private function calculatePurchaseOrderPayload(array $validated)
    {
        $lines = collect($validated['lines'])
            ->filter(function ($line) {
                return filled($line['productCode'] ?? null);
            })
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Adicione pelo menos uma linha à encomenda.',
            ]);
        }

        $products = Product::with('codeRateLink')
            ->whereIn('code', $lines->pluck('productCode')->unique()->all())
            ->get()
            ->keyBy(function ($product) {
                return (string) $product->code;
            });

        $totalNet = 0;
        $totalTax = 0;
        $detailRows = [];
        $now = now();

        foreach ($lines as $line) {
            $product = $products->get((string) $line['productCode']);

            if (!$product) {
                throw ValidationException::withMessages([
                    'lines' => 'Existe um artigo inválido no detalhe da encomenda.',
                ]);
            }

            $quantity = round((float) $line['quantity'], 3);
            $unitPrice = round((float) $line['unitPrice'], 2);
            $lineNet = round($quantity * $unitPrice, 2);
            $taxRate = (float) optional($product->codeRateLink)->taxRate;
            $lineTax = round($lineNet * ($taxRate / 100), 2);

            $totalNet += $lineNet;
            $totalTax += $lineTax;

            $detailRows[] = [
                'pONumber' => $validated['pONumber'],
                'pODateDelivery' => null,
                'productCode' => $product->code,
                'productFamily' => $product->family,
                'productUnit' => $product->unit,
                'taxRateCode' => $product->taxRateCode,
                'quantity' => $quantity,
                'deliveryQuantity' => 0,
                'dicountPercent' => 0,
                'unitPrice' => $unitPrice,
                'sellingPrice' => null,
                'status' => 0,
                'created_by' => Auth::id(),
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $financialDiscount = round((float) ($validated['financialDiscount'] ?? 0), 2);

        return [
            'financialDiscount' => $financialDiscount,
            'totalNet' => round($totalNet, 2),
            'totalTax' => round($totalTax, 2),
            'totalGross' => max(round(($totalNet + $totalTax) - $financialDiscount, 2), 0),
            'detailRows' => $detailRows,
        ];
    }

    private function getStockSummaryByProduct($productCodes = [])
    {
        $productCodes = collect($productCodes)->filter()->unique()->values();

        if ($productCodes->isEmpty()) {
            return [];
        }

        $movements = StockMovement::whereIn('productCode', $productCodes->all())
            ->orderBy('id')
            ->get();

        $summary = [];

        foreach ($productCodes as $productCode) {
            $productMovements = $movements->where('productCode', $productCode);

            $stockQuantity = $productMovements->sum(function ($movement) {
                return strtoupper((string) $movement->movementType) === 'OUT'
                    ? -1 * (float) $movement->quantity
                    : (float) $movement->quantity;
            });

            $averageCost = (float) optional($productMovements->last())->averageCostAfter;

            $summary[$productCode] = [
                'stockQuantity' => round($stockQuantity, 3),
                'averageCost' => round($averageCost, 4),
            ];
        }

        return $summary;
    }

    private function decoratePurchaseOrderSummary($purchaseOrder)
    {
        $detailLines = $purchaseOrder->detailLines ?? collect();
        $orderedQuantity = 0;
        $deliveredQuantity = 0;
        $orderedValue = 0;
        $deliveredValue = 0;
        $pendingValue = 0;

        foreach ($detailLines as $line) {
            $lineOrderedQuantity = (float) $line->quantity;
            $lineDeliveredQuantity = (float) $line->deliveryQuantity;
            $lineUnitPrice = (float) $line->unitPrice;
            $effectivePendingQuantity = max($lineOrderedQuantity - min($lineDeliveredQuantity, $lineOrderedQuantity), 0);

            $orderedQuantity += $lineOrderedQuantity;
            $deliveredQuantity += $lineDeliveredQuantity;
            $orderedValue += round($lineOrderedQuantity * $lineUnitPrice, 2);
            $deliveredValue += round($lineDeliveredQuantity * $lineUnitPrice, 2);
            $pendingValue += round($effectivePendingQuantity * $lineUnitPrice, 2);
        }

        $hasDeliveredLines = $detailLines->contains(function ($line) {
            return (float) $line->deliveryQuantity > 0;
        });
        $isFullySatisfied = $detailLines->isNotEmpty() && $detailLines->every(function ($line) {
            return (float) $line->deliveryQuantity >= (float) $line->quantity;
        });

        if ($isFullySatisfied) {
            $purchaseOrder->satisfactionFilter = 'full';
            $purchaseOrder->satisfactionLabel = 'Satisfeita';
        } elseif ($hasDeliveredLines) {
            $purchaseOrder->satisfactionFilter = 'pending';
            $purchaseOrder->satisfactionLabel = 'Por satisfazer';
        } else {
            $purchaseOrder->satisfactionFilter = 'none';
            $purchaseOrder->satisfactionLabel = 'Sem entradas';
        }

        $purchaseOrder->orderedQuantitySummary = round($orderedQuantity, 3);
        $purchaseOrder->deliveredQuantitySummary = round($deliveredQuantity, 3);
        $purchaseOrder->orderedValueSummary = round($orderedValue, 2);
        $purchaseOrder->deliveredValueSummary = round($deliveredValue, 2);
        $purchaseOrder->pendingValueSummary = round($pendingValue, 2);
        $purchaseOrder->satisfactionPercent = $orderedQuantity > 0
            ? round(min(($deliveredQuantity / $orderedQuantity) * 100, 999.99), 2)
            : 0;

        return $purchaseOrder;
    }

    private function filterPurchaseOrdersBySatisfaction($purchaseOrders, $statusFilter)
    {
        return match ($statusFilter) {
            'full' => $purchaseOrders->where('satisfactionFilter', 'full'),
            'pending' => $purchaseOrders->where('satisfactionFilter', 'pending'),
            'none' => $purchaseOrders->where('satisfactionFilter', 'none'),
            default => $purchaseOrders,
        };
    }

    private function buildPurchaseOrderAnalyticsData($allPurchaseOrders, $filteredPurchaseOrders)
    {
        $statusBreakdown = [
            'Satisfeitas' => $allPurchaseOrders->where('satisfactionFilter', 'full')->count(),
            'Por satisfazer' => $allPurchaseOrders->where('satisfactionFilter', 'pending')->count(),
            'Sem entradas' => $allPurchaseOrders->where('satisfactionFilter', 'none')->count(),
        ];

        $topSuppliers = $filteredPurchaseOrders
            ->groupBy('supplierCode')
            ->map(function ($orders, $supplierCode) {
                return [
                    'supplier' => $supplierCode . ' - ' . (optional($orders->first()->supplierLink)->name ?: '-'),
                    'value' => round($orders->sum('orderedValueSummary'), 2),
                ];
            })
            ->sortByDesc('value')
            ->take(5)
            ->values();

        $monthlyTrend = $filteredPurchaseOrders
            ->groupBy(function ($purchaseOrder) {
                return $purchaseOrder->pODate ? date('Y-m', strtotime($purchaseOrder->pODate)) : 'Sem data';
            })
            ->map(function ($orders, $month) {
                return [
                    'sortKey' => $month,
                    'month' => $month === 'Sem data' ? $month : date('m/Y', strtotime($month . '-01')),
                    'ordered' => round($orders->sum('orderedValueSummary'), 2),
                    'received' => round($orders->sum('deliveredValueSummary'), 2),
                ];
            })
            ->sortBy(function ($item) {
                return $item['sortKey'] === 'Sem data' ? '9999-99' : $item['sortKey'];
            })
            ->map(function ($item) {
                unset($item['sortKey']);

                return $item;
            })
            ->values();

        $topPendingOrders = $filteredPurchaseOrders
            ->filter(function ($purchaseOrder) {
                return (float) $purchaseOrder->pendingValueSummary > 0;
            })
            ->sortByDesc('pendingValueSummary')
            ->take(5)
            ->values()
            ->map(function ($purchaseOrder) {
                return [
                    'label' => 'EF ' . $purchaseOrder->pONumber,
                    'value' => round($purchaseOrder->pendingValueSummary, 2),
                ];
            });

        $totalOrders = $filteredPurchaseOrders->count();
        $totalOrderedValue = round($filteredPurchaseOrders->sum('orderedValueSummary'), 2);
        $totalDeliveredValue = round($filteredPurchaseOrders->sum('deliveredValueSummary'), 2);
        $totalPendingValue = round($filteredPurchaseOrders->sum('pendingValueSummary'), 2);
        $averageSatisfaction = $totalOrders > 0 ? round($filteredPurchaseOrders->avg('satisfactionPercent'), 2) : 0;

        return [
            'statusBreakdown' => $statusBreakdown,
            'topSuppliers' => $topSuppliers,
            'monthlyTrend' => $monthlyTrend,
            'topPendingOrders' => $topPendingOrders,
            'totals' => [
                'orders' => $totalOrders,
                'orderedValue' => $totalOrderedValue,
                'deliveredValue' => $totalDeliveredValue,
                'pendingValue' => $totalPendingValue,
                'averageSatisfaction' => $averageSatisfaction,
            ],
        ];
    }

    // ========================================================================
    //  OCR — Leitura de Documentos de Fornecedor
    // ========================================================================

    /**
     * Exibe a página de OCR para leitura de documentos de fornecedor.
     */
    public function showPurchaseOrderOCR()
    {
        return view('backend.purchaseOrder.purchaseOrder_ocr');
    }

    /**
     * Endpoint de teste que processa um PDF de exemplo e retorna JSON.
     */
    public function testPurchaseOrderOCR()
    {
        $testPdfPath = public_path('documents/test_invoice.pdf');

        if (!file_exists($testPdfPath)) {
            return response()->json([
                'success' => false,
                'error' => 'Ficheiro de teste não encontrado: documents/test_invoice.pdf',
            ]);
        }

        $requestId = 'test-' . uniqid();
        $text = $this->extractTextFromDocument($testPdfPath, $requestId);
        $parsedData = $this->parsePurchaseOrderDocument($text, $requestId);
        $enrichedData = $this->enrichPurchaseOrderDataWithDatabase($parsedData, $requestId);

        return response()->json([
            'success' => true,
            'requestId' => $requestId,
            'rawText' => $text,
            'parsed' => $parsedData,
            'enriched' => $enrichedData,
        ]);
    }

    /**
     * Atualiza os dados OCR na sessão após o utilizador editar os campos.
     */
    public function updatePurchaseOrderOCRData(Request $request)
    {
        $validated = $request->validate([
            'supplier.name' => 'sometimes|string|max:255',
            'supplier.nif' => 'sometimes|string|max:20',
            'lines' => 'sometimes|array',
            'lines.*.productCode' => 'sometimes|string|max:50',
            'lines.*.description' => 'sometimes|string|max:500',
            'lines.*.quantity' => 'sometimes|numeric|min:0',
            'lines.*.unitPrice' => 'sometimes|numeric|min:0',
        ]);

        $ocrData = session('ocr_purchase_order_data', []);

        if ($request->has('supplier.name')) {
            $ocrData['supplier']['name'] = $request->input('supplier.name');
        }
        if ($request->has('supplier.nif')) {
            $ocrData['supplier']['nif'] = $request->input('supplier.nif');
        }

        if ($request->has('lines')) {
            foreach ($request->input('lines') as $i => $editedLine) {
                if (isset($ocrData['lines'][$i])) {
                    if (isset($editedLine['productCode'])) {
                        $ocrData['lines'][$i]['productCode'] = $editedLine['productCode'];
                    }
                    if (isset($editedLine['description'])) {
                        $ocrData['lines'][$i]['description'] = $editedLine['description'];
                    }
                    if (isset($editedLine['quantity'])) {
                        $ocrData['lines'][$i]['quantity'] = (float) $editedLine['quantity'];
                    }
                    if (isset($editedLine['unitPrice'])) {
                        $ocrData['lines'][$i]['unitPrice'] = (float) $editedLine['unitPrice'];
                    }
                }
            }
        }

        session()->flash('ocr_purchase_order_data', $ocrData);

        return response()->json(['success' => true]);
    }

    /**
     * Processa o upload de um documento (imagem ou PDF) e retorna dados
     * estruturados (fornecedor + linhas) prontos para criar uma encomenda.
     *
     * Primeiro tenta o OCR Service (Tesseract + preprocessing + Ollama LLM).
     * Se o serviço não estiver disponível, cai para o Tesseract direto.
     */
    public function uploadPurchaseOrderDocument(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $requestId = 'po-ocr-' . uniqid();
        $file = $request->file('document');

        // ── Passo 1: Tentar o OCR Service (Python microservice) ──────────
        try {
            $ocrService = app(OcrService::class);
            $result = $ocrService->analyzeDocument($file);

            if ($result['success']) {
                $parsed = $result['data']['parsed'] ?? [];
                $rawText = $result['data']['raw_text'] ?? '';

                Log::info('[PO-OCR] Serviço OCR usado com sucesso.', [
                    'requestId' => $requestId,
                    'supplier' => $parsed['supplier']['name'] ?? 'unknown',
                    'lines' => count($parsed['lines'] ?? []),
                ]);

                // Converter formato do LLM para o formato esperado pelo enriquecimento
                $convertedData = $this->convertLLMFormatToInternal($parsed);

                // Enriquece com dados da base de dados
                $enrichedData = $this->enrichPurchaseOrderDataWithDatabase($convertedData, $requestId);

                // Verifica se as linhas têm preços válidos (> 0).
                // Se o LLM não extraiu preços, tenta o parser regex PHP
                $hasPrices = collect($enrichedData['lines'] ?? [])
                    ->contains(fn($line) => ($line['unitPrice'] ?? 0) > 0);

                $usePhpFallback = !$hasPrices && !empty($rawText);

                if ($usePhpFallback) {
                    Log::info('[PO-OCR] LLM não extraiu preços, a tentar parser PHP.', [
                        'requestId' => $requestId,
                        'rawTextLen' => strlen($rawText),
                    ]);

                    $phpParsedData = $this->parsePurchaseOrderDocument($rawText, $requestId);
                    $phpEnrichedData = $this->enrichPurchaseOrderDataWithDatabase($phpParsedData, $requestId);

                    $phpHasPrices = collect($phpEnrichedData['lines'] ?? [])
                        ->contains(fn($line) => ($line['unitPrice'] ?? 0) > 0);

                    if ($phpHasPrices) {
                        Log::info('[PO-OCR] Parser PHP extraiu preços, a usar resultado.', [
                            'requestId' => $requestId,
                            'lines' => count($phpEnrichedData['lines']),
                        ]);

                        session()->flash('ocr_purchase_order_data', $phpEnrichedData);

                        return response()->json([
                            'success' => true,
                            'requestId' => $requestId,
                            'rawText' => $rawText,
                            'parsed' => $phpParsedData,
                            'enriched' => $phpEnrichedData,
                            'ocr_service' => 'python_microservice_with_php_fallback',
                        ]);
                    }
                }

                // Guarda os dados em sessão para preencher o formulário
                session()->flash('ocr_purchase_order_data', $enrichedData);

                return response()->json([
                    'success' => true,
                    'requestId' => $requestId,
                    'rawText' => $rawText,
                    'parsed' => $convertedData,
                    'enriched' => $enrichedData,
                    'ocr_service' => 'python_microservice',
                ]);
            }

            // Serviço disponível mas falhou — log do erro
            Log::warning('[PO-OCR] Serviço OCR disponível mas falhou, a usar fallback Tesseract.', [
                'requestId' => $requestId,
                'error' => $result['error'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            Log::info('[PO-OCR] Serviço OCR não disponível, a usar fallback Tesseract.', [
                'requestId' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }

        // ── Passo 2: Fallback para o Tesseract direto ─────────────────────
        $path = $file->store('ocr-temp', 'public');
        $fullPath = storage_path('app/public/' . $path);

        try {
            $text = $this->extractTextFromDocument($fullPath, $requestId);

            if (empty(trim($text))) {
                return response()->json([
                    'success' => false,
                    'error' => 'Não foi possível extrair texto do documento. ' .
                        'Certifique-se de que a imagem tem boa resolução e contraste.',
                ]);
            }

            $parsedData = $this->parsePurchaseOrderDocument($text, $requestId);
            $enrichedData = $this->enrichPurchaseOrderDataWithDatabase($parsedData, $requestId);

            session()->flash('ocr_purchase_order_data', $enrichedData);

            return response()->json([
                'success' => true,
                'requestId' => $requestId,
                'rawText' => $text,
                'parsed' => $parsedData,
                'enriched' => $enrichedData,
                'ocr_service' => 'tesseract_fallback',
            ]);
        } catch (\Exception $e) {
            Log::error('[PO-OCR] Erro no processamento do documento.', [
                'requestId' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erro ao processar o documento: ' . $e->getMessage(),
            ]);
        } finally {
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    /**
     * Converte o formato do LLM (OCR Service) para o formato interno.
     *
     * O LLM devolve algo como:
     *   { supplier: {name, nif, address}, lines: [{productCode, productDescription, quantity, unitPrice}] }
     *
     * O formato interno espera:
     *   { supplier: {nome, nif}, lines: [{codigo, descricao, quantidade, precoUnitario}] }
     */
    private function convertLLMFormatToInternal(array $parsed): array
    {
        $supplier = $parsed['supplier'] ?? [];
        $lines = $parsed['lines'] ?? [];

        return [
            'supplier' => [
                'nome' => $supplier['name'] ?? '',
                'nif' => $supplier['nif'] ?? '',
                'email' => '',
                'telefone' => '',
                'morada' => $supplier['address'] ?? '',
            ],
            'lines' => array_map(function ($line) {
                return [
                    'codigo' => $line['productCode'] ?? '',
                    'descricao' => $line['productDescription'] ?? '',
                    'quantidade' => $line['quantity'] ?? 1,
                    'precoUnitario' => $line['unitPrice'] ?? 0,
                ];
            }, $lines),
            'documentDate' => $parsed['documentDate'] ?? null,
            'documentNumber' => $parsed['documentNumber'] ?? null,
        ];
    }

    // ========================================================================
    //  Resolução de Entidades (Fornecedor, Artigo, TaxRate)
    // ========================================================================

    /**
     * Procura ou cria um fornecedor com base nos dados extraídos do OCR.
     *
     * @param  array{nome?:string,nif?:string,email?:string,telefone?:string}  $parsedSupplier
     * @return array{found:bool,model:Supplier}
     */
    private function findOrCreateSupplierFromOcr(array $parsedSupplier): array
    {
        $nome = $this->normalizeText($parsedSupplier['nome'] ?? '');
        $nif = $this->normalizeText($parsedSupplier['nif'] ?? '');

        // 1. Tentar encontrar por NIF (se existir)
        if (!empty($nif)) {
            $byNif = Supplier::where('nif', $nif)->first();
            if ($byNif) {
                return ['found' => true, 'model' => $byNif];
            }
        }

        // 2. Tentar encontrar por nome (exacto ou fuzzy)
        if (!empty($nome)) {
            $exact = Supplier::where('name', $nome)->first();
            if ($exact) {
                return ['found' => true, 'model' => $exact];
            }

            $allSuppliers = Supplier::all(['code', 'name']);
            $bestScore = 0;
            $bestSupplier = null;

            foreach ($allSuppliers as $supplier) {
                $score = $this->calculateTextSimilarity($nome, $supplier->name);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestSupplier = $supplier;
                }
            }

            if ($bestScore >= 60 && $bestSupplier) {
                return ['found' => true, 'model' => $bestSupplier];
            }
        }

        // 3. Criar fornecedor (se permitido)
        if (!config('purchaseorder.ocr.auto_create_supplier', true)) {
            return ['found' => false, 'model' => null];
        }

        $maxCode = (int) Supplier::max('code');
        $newCode = $maxCode > 0 ? $maxCode + 1 : 1;

        $supplier = Supplier::create([
            'code' => $newCode,
            'name' => !empty($nome) ? $nome : 'Fornecedor (OCR)',
            'nif' => !empty($nif) ? $nif : null,
            'address1' => $this->normalizeText($parsedSupplier['morada'] ?? ''),
            'status' => 1,
            'created_by' => Auth::id(),
            'updated_by' => null,
        ]);

        Log::info('[PO-OCR] Fornecedor criado automaticamente.', [
            'code' => $newCode,
            'name' => $supplier->name,
        ]);

        return ['found' => false, 'model' => $supplier];
    }

    /**
     * Procura ou cria um artigo com base nos dados extraídos do OCR.
     *
     * @param  array{codigo?:string,descricao?:string,quantidade?:float,precoUnitario?:float}  $parsedLine
     * @return array{found:bool,model:Product}
     */
    private function findOrCreateProductFromOcr(array $parsedLine): array
    {
        $codigo = $this->normalizeText($parsedLine['codigo'] ?? '');
        $descricao = $this->sanitizeDescription($parsedLine['descricao'] ?? '');
        $quantidade = $this->normalizeNumber($parsedLine['quantidade'] ?? 0);
        $precoUnitario = $this->normalizeNumber($parsedLine['precoUnitario'] ?? 0);

        // 1. Tentar encontrar por código
        if (!empty($codigo)) {
            $byCode = Product::where('code', $codigo)->first();
            if ($byCode) {
                return ['found' => true, 'model' => $byCode];
            }
        }

        // 2. Tentar encontrar por descrição (exacta ou fuzzy)
        if (!empty($descricao)) {
            $exact = Product::where('description', $descricao)->first();
            if ($exact) {
                return ['found' => true, 'model' => $exact];
            }

            $allProducts = Product::all(['code', 'description']);
            $bestScore = 0;
            $bestProduct = null;

            foreach ($allProducts as $product) {
                $score = $this->calculateTextSimilarity($descricao, $product->description);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestProduct = $product;
                }
            }

            // Limiar mais baixo para evitar falsos positivos
            if ($bestScore >= 65 && $bestProduct) {
                return ['found' => true, 'model' => $bestProduct];
            }
        }

        // 3. Criar artigo (se permitido)
        if (!config('purchaseorder.ocr.auto_create_product', true)) {
            return ['found' => false, 'model' => null];
        }

        $family = $this->ensureDefaultFamily();
        $unit = $this->ensureDefaultUnit();
        $taxRateCode = $this->resolveTaxRateFromOcr(null);
        $newCode = $this->generateProductCode();

        $product = Product::create([
            'code' => $newCode,
            'description' => $descricao ?: 'Artigo (OCR)',
            'family' => $family,
            'unit' => $unit,
            'taxRateCode' => $taxRateCode,
            'status' => 1,
            'created_by' => Auth::id(),
            'updated_by' => null,
        ]);

        Log::info('[PO-OCR] Artigo criado automaticamente.', [
            'code' => $newCode,
            'description' => $product->description,
            'family' => $family,
            'unit' => $unit,
            'taxRateCode' => $taxRateCode,
        ]);

        return ['found' => false, 'model' => $product];
    }

    /**
     * Resolve o código de taxa de IVA.
     *
     * Se for fornecida uma percentagem, tenta fazer corresponder.
     * Caso contrário, usa o código configurado como fallback.
     */
    private function resolveTaxRateFromOcr(?float $taxRatePercent): int
    {
        if ($taxRatePercent !== null && $taxRatePercent > 0) {
            $match = TaxRate::where('taxRate', $taxRatePercent)->first();
            if ($match) {
                return (int) $match->taxRateCode;
            }
        }

        return (int) config('purchaseorder.ocr.default_tax_rate_code', 1);
    }

    /**
     * Garante que existe a família "GERAL" e retorna o nome.
     */
    private function ensureDefaultFamily(): string
    {
        $defaultFamily = config('purchaseorder.ocr.default_family', 'GERAL');
        $exists = Family::where('family', $defaultFamily)->exists();

        if (!$exists) {
            Family::create(['family' => $defaultFamily]);
            Log::info('[PO-OCR] Família criada automaticamente.', ['family' => $defaultFamily]);
        }

        return $defaultFamily;
    }

    /**
     * Garante que existe a unidade "UN" e retorna a sigla.
     */
    private function ensureDefaultUnit(): string
    {
        $defaultUnit = config('purchaseorder.ocr.default_unit', 'UN');
        $exists = UnitMeasure::where('unit', $defaultUnit)->exists();

        if (!$exists) {
            UnitMeasure::create(['unit' => $defaultUnit]);
            Log::info('[PO-OCR] Unidade criada automaticamente.', ['unit' => $defaultUnit]);
        }

        return $defaultUnit;
    }

    /**
     * Gera um código de artigo auto-incrementado com prefixo.
     */
    private function generateProductCode(): string
    {
        $prefix = config('purchaseorder.ocr.product_code_prefix', 'ART-');
        $allCodes = Product::where('code', 'like', $prefix . '%')
            ->get('code')
            ->map(function ($product) use ($prefix) {
                return (int) str_replace($prefix, '', $product->code);
            });

        $maxNum = $allCodes->max() ?: 0;
        $nextNum = $maxNum + 1;

        return $prefix . $nextNum;
    }

    // ========================================================================
    //  Parsing do Texto OCR
    // ========================================================================

    /**
     * Interpreta o texto extraído e devolve uma estrutura normalizada:
     *   - supplier: {nome, nif, email, telefone, morada}
     *   - lines: [{codigo, descricao, quantidade, precoUnitario}]
     */
    private function parsePurchaseOrderDocument(string $text, ?string $requestId = null): array
    {
        $lines = explode("\n", $text);
        $supplier = [
            'nome' => '',
            'nif' => '',
            'email' => '',
            'telefone' => '',
            'morada' => '',
        ];
        $parsedLines = $this->extractPOTabularLines($text);
        $linhasEncontradas = count($parsedLines);

        // --- Extração de metadados do fornecedor ---
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // NIF — tolera espaços entre dígitos (ex: "500 123 456")
            // Primeiro tenta o padrão com label e dígitos consecutivos
            if (preg_match('/\b(?:NIF|Contribuinte|Fiscal\s*N[oº]?)\s*[:\s]*(\d{9})\b/i', $trimmed, $m)) {
                $supplier['nif'] = $m[1];
            }
            // Depois tenta com espaços (ex: "500 123 456")
            elseif (preg_match('/\b(?:NIF|Contribuinte|Fiscal\s*N[oº]?)\s*[:\s]*(\d{3})\s*(\d{3})\s*(\d{3})\b/i', $trimmed, $m)) {
                $supplier['nif'] = $m[1] . $m[2] . $m[3];
            }

            // Email
            if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', $trimmed, $m)) {
                $supplier['email'] = $m[0];
            }

            // Contacto / telefone
            if (empty($supplier['telefone']) && preg_match('/\b(?:\+?\d{2,3}\s?)?\d{9}\b/', $trimmed, $m)) {
                $supplier['telefone'] = $m[0];
            }
        }

        // --- Procura label FORNECEDOR: antes da heurística da primeira linha ---
        foreach ($lines as $line) {
            if (preg_match('/\bFORNECEDOR:\s*(.+)/i', $line, $m)) {
                $nome = trim($m[1]);
                $nome = preg_replace('/\s+\d{2}\/\d{2}\/\d{4}.*$/', '', $nome);
                $nome = preg_replace('/\s+NIF:.*$/i', '', $nome);
                $nome = preg_replace('/\s+CONTACTO:.*$/i', '', $nome);
                $supplier['nome'] = trim($nome);
                break;
            }
        }

        // --- Data da encomenda ---
        foreach ($lines as $line) {
            if (preg_match('/DATA\s+DA\s+ENCOMENDA:\s*(\d{2})[\/\-](\d{2})[\/\-](\d{4})/i', $line, $m)) {
                $supplier['dataEncomenda'] = "{$m[3]}-{$m[2]}-{$m[1]}";
                break;
            }
        }

        // --- Nome do fornecedor: primeiras linhas não vazias que não pareçam cabecalho ---
        $headerKeywords = ['fatura', 'invoice', 'recibo', 'receipt', 'encomenda', 'data', 'n[oº]'];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed) || mb_strlen($trimmed) < 3) {
                continue;
            }
            $isHeader = false;
            foreach ($headerKeywords as $kw) {
                if (preg_match('/^' . $kw . '/i', $trimmed)) {
                    $isHeader = true;
                    break;
                }
            }
            if ($isHeader) {
                continue;
            }
            // Se parece um endereço, guardar como morada
            if (preg_match('/^(?:Rua|Av\.|Avenida|Travessa|Largo|Praça|Estrada)/i', $trimmed)) {
                if (empty($supplier['morada'])) {
                    $supplier['morada'] = $trimmed;
                }
                continue;
            }
            // Se parece NIF, ignorar
            if (preg_match('/^\d{9}$/', $trimmed)) {
                continue;
            }
            $supplier['nome'] = $trimmed;
            break;
        }

        Log::info('[PO-OCR] Documento interpretado.', [
            'requestId' => $requestId,
            'supplier' => $supplier,
            'linhasEncontradas' => $linhasEncontradas,
        ]);

        return [
            'supplier' => $supplier,
            'lines' => $parsedLines,
            'documentDate' => $supplier['dataEncomenda'] ?? null,
        ];
    }

    /**
     * Extrai linhas tabulares (artigos) a partir do texto OCR.
     *
     * Procura por padrões como:
     *   - Código + Descrição + Quantidade + Preço
     *   - Descrição + Quantidade + Preço
     *   - Linhas com formato de colchetes/pipes (formatos de tabela de fornecedor)
     *
     * NOTA: O OCR frequëntemente extrai texto com espaços simples entre colunas
     * (não 2+), por isso esta implementação tolera \s+ em vez de \s{2,}.
     * A lógica usa o facto de que as últimas palavras numérica da linha são
     * tipicamente a quantidade e o preço unitário.
     */
    private function extractPOTabularLines(string $text): array
    {
        $lines = explode("\n", $text);
        $results = [];
        $currentDescription = '';

        foreach ($lines as $raw) {
            $line = trim($raw);
            if (empty($line)) {
                continue;
            }

            // ── Passo 1: Padrão de colchetes/pipes (formato tabela fornecedor) ──
            // Ex: "0122 [Batata Monalisa Sac 10kg | 5[Cx] 8,60] 6%"
            // Ex: "0551 Salsa Frisada Molho 20] UN 6%"
            // NOTA: O regex usa descrição non-greedy (.+?) para isolar código + qtd/preço.
            if (preg_match('/^' .
                '(\d{2,4}|[A-Z]+-\d+)\s*' .     // Código
                '\[?' .                           // [ opcional
                '(.+?)' .                         // Descrição (non-greedy para priorizar o código + qtd/preço)
                '(?:[\s\[\|]+)?' .                // Separadores entre descrição e números
                '(\d+(?:[.,]\d+)?)\s*' .          // Quantidade
                '(?:\[?[A-Za-z]+\]?\s*)?' .       // Unidade opcional [Cx], [KG], [UN]
                '(\d+(?:[.,]\d+)?)\s*' .          // Preço unitário
                '(?:\s+.*)?$/u',     // Conteúdo final opcional (IVA%, totais, etc.)
                $line, $m
            )) {
                $descricao = $this->sanitizeDescription($m[2]);
                if (!empty($descricao)) {
                    $results[] = [
                        'codigo' => $m[1],
                        'descricao' => $descricao,
                        'quantidade' => $this->normalizeNumber($m[3]),
                        'precoUnitario' => $this->normalizeNumber($m[4]),
                    ];
                    $currentDescription = '';
                    continue;
                }
            }

            // ── Passo 2: Padrão com números no fim da linha ──
            // Ex: "ART-001 Parafuso Inox M8x20 10 2,50"
            // Ex: "1001 Arroz Agulha Cacarola kg 20 20 1,15"
            // Ex: "Parafuso Inox M8x20 10 x 2,50"
            // Remove € de toda a linha ANTES de qualquer parsing (ex: "1,15€" -> "1,15")
            $lineNoEuro = str_replace('€', '', $line);
            $cleanedLine = $lineNoEuro;
            // Limpa artefactos de fim de linha (totais, IVA%) antes de capturar qtd/preço
            $cleanedLine = preg_replace('/\s*\d+\s*%\s*(?:\|\s*\d+(?:[.,]\d+)?)?\s*\)?\s*$/u', '', $cleanedLine);
            $cleanedLine = preg_replace('/\s*\|\s*\d+(?:[.,]\d+)?\s*\)?\s*$/u', '', $cleanedLine);

            // Extrai código se existir (no início da linha)
            $codigo = '';
            $descricao = $cleanedLine;
            if (preg_match('/^(\d{2,4}|[A-Z]+-\d+)\s+(.+)$/u', $cleanedLine, $codeMatch)) {
                $codigo = $codeMatch[1];
                $descricao = $codeMatch[2];
            }

            // Extrai apenas os números NO FINAL da descrição (ignora números embutidos
            // como "10" em "10kg" ou "8" em "M8x20").
            // O "x" entre qtd e preço (ex: "10 x 2,50") também é suportado.
            $hasTrailingNumbers = preg_match(
                '/((?:\d+(?:[.,]\d+)?(?:\s+(?:[xX\*]\s*)?|\s*$)){2,})$/u',
                $descricao,
                $trailingMatch
            );

            if ($hasTrailingNumbers && !$this->isLineExcluded($lineNoEuro)) {
                $trailingStr = trim($trailingMatch[1]);
                $trailingNumbers = preg_split('/\s+/', $trailingStr);
                $tCount = count($trailingNumbers);

                // Remove TODOS os números do final da descrição (genérico, não depende de qtd/preço específico)
                $descricao = substr($descricao, 0, -strlen($trailingMatch[1]));
                $descricao = $this->sanitizeDescription($descricao);

                if (!empty($descricao)) {
                    // Determina qty e price a partir dos números do final
                    if ($tCount >= 4) {
                        // 4+ números no final: qtd_encomendada qtd_confirmada preco_unit total
                        $qtyStr = $trailingNumbers[0];
                        $priceStr = $trailingNumbers[$tCount - 2];
                    } elseif ($tCount == 3) {
                        // 3 números no final: pode ser [qtd, preco, total] ou [qtd, extra, preco]
                        $lastNum = $this->normalizeNumber($trailingNumbers[2]);
                        $secondLastNum = $this->normalizeNumber($trailingNumbers[1]);
                        if ($secondLastNum > 0 && $lastNum / $secondLastNum > 1.5) {
                            // Relação total/preco > 1.5: [qtd, preco, total]
                            $qtyStr = $trailingNumbers[0];
                            $priceStr = $trailingNumbers[1];
                        } else {
                            // [qtd, extra (ex: qtd recebida), preco] — preco é o último
                            $qtyStr = $trailingNumbers[0];
                            $priceStr = $trailingNumbers[2];
                        }
                    } else {
                        // 2 números no final: [qtd, preco]
                        $qtyStr = $trailingNumbers[0];
                        $priceStr = $trailingNumbers[1];
                    }

                    $results[] = [
                        'codigo' => $codigo,
                        'descricao' => $descricao,
                        'quantidade' => $this->normalizeNumber($qtyStr),
                        'precoUnitario' => $this->normalizeNumber($priceStr),
                    ];
                    $currentDescription = '';
                    continue;
                }
            }

            // ── Passo 3: Acumula descrição para linhas multi-linha ──
            if (preg_match('/^[\s\S]{3,60}$/u', $line) && !$this->isLineExcluded($line)) {
                $currentDescription .= ' ' . $line;
            }
        }

        return $results;
    }

    /**
     * Verifica se uma linha OCR deve ser excluída do parsing de artigos.
     */
    private function isLineExcluded(string $line): bool
    {
        return (bool) preg_match(
            '/(?:total|iva|subtotal|eur|nif|contribuinte|data|página|page|observaç|condiç|obs\.)/i',
            $line
        );
    }

    /**
     * Enriquece os dados interpretados com informação da base de dados.
     *
     * Para cada artigo, tenta resolver o produto existente ou cria um novo.
     * O mesmo para o fornecedor.
     */
    private function enrichPurchaseOrderDataWithDatabase(array $parsedData, ?string $requestId = null): array
    {
        // --- Fornecedor ---
        $supplierResult = $this->findOrCreateSupplierFromOcr($parsedData['supplier'] ?? []);
        $supplierModel = $supplierResult['model'] ?? null;

        $enrichedSupplier = [
            'code' => $supplierModel ? (string) $supplierModel->code : '',
            'name' => $supplierModel ? $supplierModel->name : ($parsedData['supplier']['nome'] ?? ''),
            'nif' => $supplierModel ? $supplierModel->nif : ($parsedData['supplier']['nif'] ?? ''),
            'found' => $supplierResult['found'] ?? false,
        ];

        // --- Linhas ---
        $enrichedLines = [];

        foreach (($parsedData['lines'] ?? []) as $i => $line) {
            $productResult = $this->findOrCreateProductFromOcr($line);
            $productModel = $productResult['model'] ?? null;

            $taxRateCode = $productModel ? (int) $productModel->taxRateCode : $this->resolveTaxRateFromOcr(null);
            $taxRatePercent = (float) TaxRate::where('taxRateCode', $taxRateCode)->value('taxRate') ?? 0;

            $enrichedLines[] = [
                'productCode' => $productModel ? (string) $productModel->code : ($line['codigo'] ?? ''),
                'description' => $productModel ? $productModel->description : ($line['descricao'] ?? ''),
                'productFamily' => $productModel ? $productModel->family : $this->ensureDefaultFamily(),
                'productUnit' => $productModel ? $productModel->unit : $this->ensureDefaultUnit(),
                'taxRateCode' => $taxRateCode,
                'taxRate' => $taxRatePercent,
                'quantity' => $this->normalizeNumber($line['quantidade'] ?? 1),
                'unitPrice' => $this->normalizeNumber($line['precoUnitario'] ?? 0),
                'found' => $productResult['found'] ?? false,
            ];
        }

        Log::info('[PO-OCR] Dados enriquecidos com BD.', [
            'requestId' => $requestId,
            'supplier' => $enrichedSupplier,
            'linesCount' => count($enrichedLines),
        ]);

        return [
            'supplier' => $enrichedSupplier,
            'lines' => $enrichedLines,
        ];
    }

    // ========================================================================
    //  Extração de Texto (Documento / PDF / Imagem)
    // ========================================================================

    /**
     * Extrai texto de um ficheiro (PDF ou imagem).
     */
    private function extractTextFromDocument(string $path, ?string $requestId = null): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return $this->extractTextFromPdf($path, $requestId);
        }

        return $this->runTesseractOnImage($path, $requestId);
    }

    /**
     * Extrai texto de um PDF convertendo para imagem com pdftoppm e aplicando OCR.
     */
    private function extractTextFromPdf(string $pdfPath, ?string $requestId = null): string
    {
        if (!$this->isCommandAvailable('pdftoppm')) {
            Log::warning('[PO-OCR] pdftoppm não está disponível.', ['requestId' => $requestId]);
            return '';
        }

        $tempDir = storage_path('app/temp-ocr-' . uniqid());
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $outputBase = $tempDir . '/page';
            $cmd = sprintf(
                'pdftoppm -png -r 300 "%s" "%s" 2>&1',
                $pdfPath,
                $outputBase
            );
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                Log::error('[PO-OCR] Erro pdftoppm.', [
                    'requestId' => $requestId,
                    'exitCode' => $exitCode,
                    'output' => implode("\n", $output),
                ]);
                return '';
            }

            $images = glob($tempDir . '/page-*.png');
            if (empty($images)) {
                $images = glob($tempDir . '/*.png');
            }
            sort($images);

            $fullText = '';

            foreach ($images as $imagePath) {
                $pageText = $this->runTesseractOnImage($imagePath, $requestId);
                $fullText .= $pageText . "\n--- PAGE BREAK ---\n";
            }

            return trim($fullText);
        } finally {
            if (is_dir($tempDir)) {
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
            }
        }
    }

    /**
     * Aplica Tesseract OCR a uma imagem.
     */
    private function runTesseractOnImage(string $path, ?string $requestId = null): string
    {
        try {
            $ocr = new TesseractOCR($path);
            $ocr->lang('por');
            $text = $ocr->run();

            Log::info('[PO-OCR] Tesseract executado.', [
                'requestId' => $requestId,
                'path' => $path,
                'length' => mb_strlen(trim($text)),
            ]);

            return trim($text);
        } catch (\Exception $e) {
            Log::error('[PO-OCR] Erro Tesseract.', [
                'requestId' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Verifica se um comando está disponível no sistema.
     */
    private function isCommandAvailable(string $command): bool
    {
        $process = proc_open(
            "where {$command} 2>NUL || which {$command} 2>/dev/null",
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return $exitCode === 0 && !empty(trim($stdout));
        }

        return false;
    }

    // ========================================================================
    //  Utilitários de Texto
    // ========================================================================

    /**
     * Calcula a similaridade entre dois textos, combinando
     * similar_text (PHP) com correspondência de tokens.
     */
    private function calculateTextSimilarity(string $left, string $right): float
    {
        $left = $this->normalizeText($left);
        $right = $this->normalizeText($right);

        if (empty($left) || empty($right)) {
            return 0.0;
        }

        // Similaridade de caracteres
        $charSimilarity = 0.0;
        similar_text($left, $right, $charSimilarity);

        // Similaridade de tokens (palavras)
        $leftTokens = preg_split('/\s+/', $left);
        $rightTokens = preg_split('/\s+/', $right);
        $commonTokens = array_intersect($leftTokens, $rightTokens);
        $totalTokens = count(array_unique(array_merge($leftTokens, $rightTokens)));
        $tokenSimilarity = $totalTokens > 0 ? (count($commonTokens) / $totalTokens) * 100 : 0;

        // Média ponderada (peso maior para tokens)
        return ($charSimilarity * 0.3) + ($tokenSimilarity * 0.7);
    }

    /**
     * Normaliza um texto para comparação (lowercase, trim, remove acentos).
     */
    private function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value, 'UTF-8');

        // Remove acentos
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        // Remove caracteres não alfanuméricos duplicados
        $value = preg_replace('/[^\w\s@.-]/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }

    /**
     * Converte uma string com possível separador português para float.
     * Ex: "1.234,56" → 1234.56  |  "1234.56" → 1234.56
     */
    private function normalizeNumber(string $value): float
    {
        $value = trim(str_replace(' ', '', $value));

        if (empty($value)) {
            return 0.0;
        }

        // Se tem ponto como milhar e vírgula como decimal
        // Ex: 1.234,56
        if (preg_match('/^\d{1,3}(?:\.\d{3})*(?:,\d+)?$/', $value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',') && !str_contains($value, '.')) {
            // Vírgula como decimal
            $value = str_replace(',', '.', $value);
        }

        return (float) $value;
    }

    /**
     * Limpa uma descrição extraída por OCR.
     *
     * Remove artefactos comuns de documentos com formato tabular:
     * - Colchetes [ ] e pipes | usados como separadores de coluna
     * - Percentagens e unidades que "contaminam" a descrição
     * - Cabeçalhos de colunas tabulares
     */
    private function sanitizeDescription(string $value): string
    {
        $value = trim($value);

        // 1. Remove cabeçalhos de colunas tabulares
        $value = preg_replace(
            '/^(?:codigo|código|artigo|referencia|ref\.?|descricao|descrição|quantidade|preco|preço|unitario|unitário)\b.*$/i',
            '',
            $value
        );

        // 2. Remove colchete de abertura no início
        $value = preg_replace('/^\[/', '', $value);

        // 3. Remove tudo após um colchete de fecho (fim da célula da tabela)
        //    Ex: "Couve Coração Seleção | 12[UN|oss| 6%" → "Couve Coração Seleção"
        $value = preg_replace('/\s*\].*$/', '', $value);

        // 4. Remove pipe + conteúdo residual (unidades, percentagens, etc.)
        //    Ex: "Batata Monalisa Sac 10kg | 5[Cx]" → "Batata Monalisa Sac 10kg"
        //    Ex: " | ex]" → já tratado acima, mas pipe sozinho também
        $value = preg_replace('/\s*\|\s*\d*\[?[A-Za-z]*(?:\]?\s*\d*\s*%?.*)?$/', '', $value);

        // 5. Remove pipes ao final
        $value = preg_replace('/\s*\|\s*$/', '', $value);

        // 6. Remove percentagens no final (ex: " 6%", " 23%")
        $value = preg_replace('/\s*\d+\s*%\s*$/', '', $value);

        // 7. Remove conteúdos entre colchetes (ex: [UN], [Cx], [KG])
        $value = preg_replace('/\s*\[[^\]]*\]/', '', $value);

        // 8. Remove parêntesis de fecho no final
        $value = preg_replace('/\s*\)\s*$/', '', $value);

        // 9. Remove múltiplos espaços
        $value = preg_replace('/\s+/', ' ', $value);

        // 10. Limita a 100 caracteres
        if (mb_strlen($value) > 100) {
            $value = mb_substr($value, 0, 100);
        }

        return trim($value);
    }
}
