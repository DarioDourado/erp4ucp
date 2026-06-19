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
use Illuminate\Support\Facades\Cache;
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

        $allPurchaseOrders = Cache::remember('purchase_orders_analytics', 300, function () {
            return PurchaseOrderC::with(['supplierLink', 'detailLines'])
                ->orderBy('pODate', 'DESC')
                ->orderBy('pONumber', 'DESC')
                ->get()
                ->map(fn ($purchaseOrder) => $this->decoratePurchaseOrderSummary($purchaseOrder));
        });

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
        $nextPONumber = ((int) PurchaseOrderC::max('pONumber')) + 1;

        // --- OCR pre-fill: verificar se há dados de OCR em sessão ---
        $ocrData = session('ocr_purchase_order_data');
        $ocrSupplierCode = $request->query('supplier_code');
        $initialLines = $this->normalizeLinesForForm(collect(old('lines', [])));

        if ($ocrData && empty(old())) {
            // Ensure products and supplier exist in DB before showing the form
            // The add form JS requires product codes to exist in the product index
            $parsedForCreation = [
                'supplier' => [
                    'nome' => $ocrData['supplier']['name'] ?? '',
                    'nif' => $ocrData['supplier']['nif'] ?? '',
                    'morada' => '',
                ],
                'lines' => array_map(function ($line) {
                    return [
                        'codigo' => $line['productCode'] ?? ($line['ocrRaw']['codigo'] ?? ''),
                        'descricao' => $line['description'] ?? ($line['ocrRaw']['descricao'] ?? ''),
                        'quantidade' => $line['quantity'] ?? 1,
                        'precoUnitario' => $line['unitPrice'] ?? 0,
                        'unidade' => $line['productUnit'] ?? ($line['ocrRaw']['unidade'] ?? null),
                        'iva' => $line['taxRate'] ?? ($line['ocrRaw']['iva'] ?? null),
                    ];
                }, array_filter($ocrData['lines'] ?? [], function ($line) {
                    return ($line['enabled'] ?? true) !== false;
                })),
            ];
            $enriched = $this->enrichPurchaseOrderDataWithDatabase($parsedForCreation, 'add-resolve', true);

            $ocrSupplierCode = $enriched['supplier']['code'] ?? $ocrSupplierCode;

            // Mapear fornecedor por NIF: se já existe um fornecedor com o NIF do OCR, assume esse
            $ocrNif = $ocrData['supplier']['nif'] ?? null;
            if (!empty($ocrNif)) {
                $supplierByNif = Supplier::where('nif', $ocrNif)->first();
                if ($supplierByNif) {
                    $ocrSupplierCode = (string) $supplierByNif->code;
                }
            }

            $initialLines = collect($enriched['lines'] ?? [])
                ->filter(function ($line) {
                    return ($line['enabled'] ?? true) !== false;
                })
                ->map(function ($line) {
                    $unitPriceWithIva = (float) ($line['unitPrice'] ?? 0);
                    $taxRate = (float) ($line['taxRate'] ?? 0);
                    $unitPrice = $taxRate > 0
                        ? $unitPriceWithIva / (1 + $taxRate / 100)
                        : $unitPriceWithIva;

                    return [
                        'productCode' => (string) ($line['productCode'] ?? ''),
                        'quantity' => (float) ($line['quantity'] ?? 1),
                        'unitPrice' => $unitPrice,
                    ];
                })
                ->values();
        }

        // Re-fetch products after potential OCR creation, so newly created
        // products are available in the form's product picker JS
        $products = $this->getProductsForForm();

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
        // Se existir dados OCR na sessão, garantir que produtos/fornecedor existem na BD
        $ocrData = session('ocr_purchase_order_data');
        if ($ocrData && !empty($ocrData['lines'])) {
            $parsedForCreation = [
                'supplier' => [
                    'nome' => $ocrData['supplier']['name'] ?? '',
                    'nif' => $ocrData['supplier']['nif'] ?? '',
                ],
                'lines' => array_map(function ($line) {
                    return [
                        'codigo' => $line['productCode'] ?? ($line['ocrRaw']['codigo'] ?? ''),
                        'descricao' => $line['description'] ?? ($line['ocrRaw']['descricao'] ?? ''),
                        'quantidade' => $line['quantity'] ?? 1,
                        'precoUnitario' => $line['unitPrice'] ?? 0,
                        'unidade' => $line['productUnit'] ?? ($line['ocrRaw']['unidade'] ?? null),
                        'iva' => $line['taxRate'] ?? ($line['ocrRaw']['iva'] ?? null),
                    ];
                }, array_filter($ocrData['lines'], function ($line) {
                    return ($line['enabled'] ?? true) !== false;
                })),
            ];
            $this->enrichPurchaseOrderDataWithDatabase($parsedForCreation, 'store-resolve', true);
            session()->forget('ocr_purchase_order_data');
        }

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

        Cache::forget('purchase_orders_analytics');
        Cache::forget('pending_receipts_analytics');

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

        Cache::forget('purchase_orders_analytics');
        Cache::forget('pending_receipts_analytics');

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

        Cache::forget('purchase_orders_analytics');
        Cache::forget('pending_receipts_analytics');

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
            'lines.*.enabled' => 'sometimes|boolean',
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
                    if (array_key_exists('enabled', $editedLine)) {
                        $ocrData['lines'][$i]['enabled'] = (bool) $editedLine['enabled'];
                    }
                }
            }
        }

        session()->put('ocr_purchase_order_data', $ocrData);

        return response()->json(['success' => true]);
    }

    /**
     * Processa o upload de um documento (imagem ou PDF) e retorna dados
     * estruturados (fornecedor + linhas) prontos para criar uma encomenda.
     *
     * Primeiro tenta o OCR Service (EasyOCR + preprocessing + LLM).
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
        $ocrServiceFailed = false;
        $result = null;

        try {
            $ocrService = app(OcrService::class);
            $result = $ocrService->analyzeDocument($file);
        } catch (\Exception $e) {
            Log::info('[PO-OCR] Serviço OCR não disponível, a usar fallback Tesseract.', [
                'requestId' => $requestId,
                'error' => $e->getMessage(),
            ]);
            $ocrServiceFailed = true;
        }

        // Se o serviço OCR respondeu com sucesso, processa os dados
        if (!$ocrServiceFailed && ($result['success'] ?? false)) {
            try {
                $parsed = $result['data']['parsed'] ?? [];
                $rawText = $result['data']['raw_text'] ?? '';
                $assembledText = $result['data']['ocr_assembled'] ?? $rawText;

                Log::info('[PO-OCR] Serviço OCR usado com sucesso.', [
                    'requestId' => $requestId,
                    'supplier' => $parsed['supplier']['name'] ?? 'unknown',
                    'lines' => count($parsed['lines'] ?? []),
                ]);

                // ── DIAGNOSTIC: Log raw OCR text and assembled text ──
                Log::info('[PO-OCR] DIAGNOSTIC rawText (' . strlen($rawText) . ' chars): ' . substr($rawText, 0, 2000));
                Log::info('[PO-OCR] DIAGNOSTIC assembledText (' . strlen($assembledText) . ' chars): ' . substr($assembledText, 0, 2000));
                // Log each parsed line from the LLM/Python parser
                foreach (($parsed['lines'] ?? []) as $i => $line) {
                    Log::info("[PO-OCR] DIAGNOSTIC Python parsed line[$i]", [
                        'productCode' => $line['productCode'] ?? null,
                        'productDescription' => $line['productDescription'] ?? null,
                        'quantity' => $line['quantity'] ?? null,
                        'unitPrice' => $line['unitPrice'] ?? null,
                    ]);
                }

                // Converter formato do LLM para o formato esperado pelo enriquecimento
                $convertedData = $this->convertLLMFormatToInternal($parsed);

                // Enriquece com dados da base de dados
                $enrichedData = $this->enrichPurchaseOrderDataWithDatabase($convertedData, $requestId);

                // ── Always compare LLM output with PHP parser when text is available ──
                $textForPhpParser = !empty($assembledText) ? $assembledText : $rawText;
                $llmLineCount = count($enrichedData['lines'] ?? []);
                $finalData = $enrichedData;
                $ocrServiceLabel = 'python_microservice';

                if (!empty($textForPhpParser)) {
                    $phpParsedData = $this->parsePurchaseOrderDocument($textForPhpParser, $requestId);
                    $phpEnrichedData = $this->enrichPurchaseOrderDataWithDatabase($phpParsedData, $requestId);

                    $phpLineCount = count($phpEnrichedData['lines'] ?? []);
                    $phpHasPrices = collect($phpEnrichedData['lines'] ?? [])
                        ->contains(fn($line) => ($line['unitPrice'] ?? 0) > 0);
                    $phpHasDescriptions = collect($phpEnrichedData['lines'] ?? [])
                        ->contains(fn($line) => !empty(trim($line['description'] ?? '')));
                    $hasPrices = collect($enrichedData['lines'] ?? [])
                        ->contains(fn($line) => ($line['unitPrice'] ?? 0) > 0);
                    $hasDescriptions = collect($enrichedData['lines'] ?? [])
                        ->contains(fn($line) => !empty(trim($line['description'] ?? '')));

                    // Log PHP parser output for comparison
                    Log::info("[PO-OCR] DIAGNOSTIC PHP parser result: supplier=" . json_encode($phpParsedData['supplier'] ?? []));
                    foreach (($phpParsedData['lines'] ?? []) as $i => $line) {
                        Log::info("[PO-OCR] DIAGNOSTIC PHP parsed line[$i]", [
                            'codigo' => $line['codigo'] ?? null,
                            'descricao' => $line['descricao'] ?? null,
                            'quantidade' => $line['quantidade'] ?? null,
                            'precoUnitario' => $line['precoUnitario'] ?? null,
                        ]);
                    }

                    // Compute average prices for comparison
                    $llmAvgPrice = collect($enrichedData['lines'] ?? [])
                        ->filter(fn($l) => ($l['unitPrice'] ?? 0) > 0)
                        ->avg('unitPrice') ?? 0;
                    $phpAvgPrice = collect($phpEnrichedData['lines'] ?? [])
                        ->filter(fn($l) => ($l['unitPrice'] ?? 0) > 0)
                        ->avg('unitPrice') ?? 0;

                    $priceDiscrepancy = $llmAvgPrice > 0 ? abs($phpAvgPrice - $llmAvgPrice) / $llmAvgPrice : ($phpAvgPrice > 0 ? 1 : 0);
                    $phpHasMoreLines = $phpLineCount > $llmLineCount;
                    $phpHasBetterPrices = ($phpHasPrices && !$hasPrices);
                    $phpHasBetterDescriptions = ($phpHasDescriptions && !$hasDescriptions);
                    $priceDiscrepancyLarge = $priceDiscrepancy > 0.5 && $llmLineCount > 0;

                    $usePhp = $phpLineCount > 0 && (
                        $phpHasMoreLines
                        || $phpHasBetterPrices
                        || $phpHasBetterDescriptions
                        || $priceDiscrepancyLarge
                    );

                    Log::info('[PO-OCR] LLM vs PHP comparison.', [
                        'requestId' => $requestId,
                        'llmLines' => $llmLineCount,
                        'phpLines' => $phpLineCount,
                        'llmAvgPrice' => $llmAvgPrice,
                        'phpAvgPrice' => $phpAvgPrice,
                        'priceDiscrepancy' => round($priceDiscrepancy * 100, 1) . '%',
                        'usePhp' => $usePhp,
                    ]);

                    if ($usePhp) {
                        $finalData = $phpEnrichedData;
                        $ocrServiceLabel = 'python_microservice_with_php_fallback';
                    }
                }

                session()->put('ocr_purchase_order_data', $finalData);

                return response()->json([
                    'success' => true,
                    'requestId' => $requestId,
                    'rawText' => $rawText,
                    'parsed' => $convertedData,
                    'enriched' => $finalData,
                    'ocr_service' => $ocrServiceLabel,
                ]);
            } catch (\Exception $e) {
                // Erro durante o processamento/enriquecimento dos dados —
                // NÃO cair para o Tesseract; o serviço OCR funcionou mas o
                // processamento dos dados falhou (ex: erro de BD).
                Log::error('[PO-OCR] Erro ao processar dados extraídos pelo OCR Service.', [
                    'requestId' => $requestId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Erro ao processar os dados extraídos do documento: ' . $e->getMessage(),
                ]);
            }
        }

        // Serviço disponível mas devolveu erro
        if (!$ocrServiceFailed && !($result['success'] ?? false)) {
            Log::warning('[PO-OCR] Serviço OCR disponível mas falhou, a usar fallback Tesseract.', [
                'requestId' => $requestId,
                'error' => $result['error'] ?? 'unknown',
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

            session()->put('ocr_purchase_order_data', $enrichedData);

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
     *   { supplier: {name, nif, address}, lines: [{productCode, productDescription, quantity, unitPrice, unit}] }
     *
     * O formato interno espera:
     *   { supplier: {nome, nif}, lines: [{codigo, descricao, quantidade, precoUnitario, unidade}] }
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
                    'descricao' => $this->sanitizeDescription((string) ($line['productDescription'] ?? '')),
                    'quantidade' => $line['quantity'] ?? 1,
                    'precoUnitario' => $line['unitPrice'] ?? 0,
                    'unidade' => $line['unit'] ?? null,
                    'iva' => isset($line['taxRate']) ? (float) $line['taxRate'] : null,
                ];
            }, $lines),
            'documentDate' => $parsed['documentDate'] ?? null,
            'documentNumber' => $parsed['documentNumber'] ?? null,
            'documentSubtotal' => (string) ($parsed['subtotal'] ?? ''),
            'documentTotal' => (string) ($parsed['total'] ?? ''),
            'documentTaxes' => $parsed['taxes'] ?? [],
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
    private function findOrCreateSupplierFromOcr(array $parsedSupplier, bool $createIfMissing = false): array
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
        if (!$createIfMissing) {
            return ['found' => false, 'model' => null];
        }

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
     * @param  array{codigo?:string,descricao?:string,quantidade?:float,precoUnitario?:float,unidade?:string}  $parsedLine
     * @return array{found:bool,model:Product}
     */
    private function findOrCreateProductFromOcr(array $parsedLine, bool $createIfMissing = false): array
    {
        $codigo = $this->normalizeText($parsedLine['codigo'] ?? '');
        $descricao = $this->sanitizeDescription($parsedLine['descricao'] ?? '');
        $quantidade = $this->normalizeNumber($parsedLine['quantidade'] ?? 0);
        $precoUnitario = $this->normalizeNumber($parsedLine['precoUnitario'] ?? 0);
        $unidadeExtraida = trim((string) ($parsedLine['unidade'] ?? ''));

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
        if (!$createIfMissing) {
            return ['found' => false, 'model' => null];
        }

        if (!config('purchaseorder.ocr.auto_create_product', true)) {
            return ['found' => false, 'model' => null];
        }

        // Só cria artigo se tiver uma descrição minimamente útil
        $descTrimmed = trim($descricao);
        if (empty($descTrimmed) || mb_strlen($descTrimmed) < 2) {
            return ['found' => false, 'model' => null];
        }

        $family = $this->ensureDefaultFamily();
        $unit = $this->resolveUnitFromOcr($unidadeExtraida);
        $lineIva = isset($parsedLine['iva']) ? (float) $parsedLine['iva'] : null;
        $taxRateCode = $this->resolveTaxRateFromOcr($lineIva);
        $newCode = $this->generateProductCode();

        $product = Product::create([
            'code' => $newCode,
            'description' => $descTrimmed,
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

            // Auto-create the tax rate entry if it doesn't exist yet
            $roundedCode = (int) round($taxRatePercent);
            $created = TaxRate::firstOrCreate(
                ['taxRateCode' => $roundedCode],
                [
                    'descriptionTaxRate' => 'IVA ' . $roundedCode . '%',
                    'taxRate' => $taxRatePercent,
                    'status' => 1,
                ]
            );

            Log::info('[PO-OCR] Taxa de IVA criada automaticamente.', [
                'taxRateCode' => $created->taxRateCode,
                'taxRate' => $created->taxRate,
            ]);

            return (int) $created->taxRateCode;
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
     * Resolve a unidade extraída do OCR para uma unidade válida na BD.
     *
     * Se a unidade extraída for válida (UN, CX, KG, etc.) e existir na BD, usa-a.
     * Caso contrário, cria-a se necessário ou devolve o fallback (UN).
     */
    private function resolveUnitFromOcr(string $ocrUnit): string
    {
        $ocrUnit = strtoupper(trim($ocrUnit));

        if (empty($ocrUnit)) {
            return $this->ensureDefaultUnit();
        }

        $exists = UnitMeasure::where('unit', $ocrUnit)->first();
        if ($exists) {
            return $ocrUnit;
        }

        // Tenta criar a unidade extraída se for uma abreviatura comum
        $knownUnits = ['UN', 'CX', 'KG', 'G', 'L', 'LT', 'ML', 'M', 'MM', 'CM', 'PCT', 'SAC', 'MOLHO', 'DOSE', 'HRS', 'HR'];
        if (in_array($ocrUnit, $knownUnits, true)) {
            UnitMeasure::create(['unit' => $ocrUnit]);
            Log::info('[PO-OCR] Unidade criada automaticamente (extraída do OCR).', ['unit' => $ocrUnit]);
            return $ocrUnit;
        }

        return $this->ensureDefaultUnit();
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
            if (preg_match('/Data\s+Emiss[ãa]o\s*:?\s*(\d{2})[\/\-](\d{2})[\/\-](\d{4})/i', $line, $m)) {
                $supplier['dataEncomenda'] = "{$m[3]}-{$m[2]}-{$m[1]}";
                break;
            }
            if (preg_match('/Entrega\s+Prevista\s*:?\s*(\d{2})[\/\-](\d{2})[\/\-](\d{4})/i', $line, $m)) {
                // This is the delivery date — store it separately and also as the document date
                $supplier['dataEncomenda'] = "{$m[3]}-{$m[2]}-{$m[1]}";
                break;
            }
        }

        // --- Nome do fornecedor: primeiras linhas não vazias que não pareçam cabecalho ---
        $headerKeywords = [
            'fatura', 'invoice', 'recibo', 'receipt', 'encomenda', 'data',
            'n[oº]', 'nota', 'fornecedor', 'cliente', 'documento', 'entrega',
            'prevista', 'emiss', 'c[oó]digo', 'artigo', 'descri[çc]',
            'qtd', 'quant', 'pre[çc]o', 'total', 'iva', 'taxa',
            'tel', 'telefone', 'contacto', 'p[áa]gina',
        ];
        $supplierNameCandidate = null;

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

            // Skip date patterns (e.g., "24/05/26, 10:10", "24/05/2026")
            if (preg_match('#^\d{1,2}\s*[/\-]\s*\d{1,2}\s*[/\-]\s*\d{2,4}#', $trimmed)) {
                continue;
            }

            // Skip single-word labels (likely headers, not company names)
            $wordCount = count(preg_split('/\s+/', $trimmed));
            if ($wordCount === 1 && mb_strlen($trimmed) <= 15) {
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

            // Store first reasonable candidate
            if ($supplierNameCandidate === null) {
                $supplierNameCandidate = $trimmed;
            }

            // If line has a company suffix (Lda, S.A., etc.), use it immediately
            if (preg_match('/\b(Lda\.?|S\.A\.|S\.?A\b|Unipessoal|LTDA|Ltda\.?|CRL)\b/i', $trimmed)) {
                $supplier['nome'] = $trimmed;
                break;
            }
        }

        // Fallback: use first reasonable candidate if no company-suffix line found
        if (empty($supplier['nome']) && $supplierNameCandidate !== null) {
            $supplier['nome'] = $supplierNameCandidate;
        }

        Log::info('[PO-OCR] Documento interpretado.', [
            'requestId' => $requestId,
            'supplier' => $supplier,
            'linhasEncontradas' => $linhasEncontradas,
        ]);

        // --- Extração de sub-totais e totais do documento ---
        $documentSubtotal = '0';
        $documentTotal = '0';
        $documentTaxes = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Subtotal (s/ IVA) — tolera OCR artifacts como "sl" em vez de "subtotal"
            if (preg_match('/(?:Subtotal|Sub\s*total|sl)\s*(?:\(?s\/?\s*IVA\)?)?\s*:?\s*([\d.,]+)\s*€?/i', $trimmed, $m)) {
                $documentSubtotal = $m[1];
            }

            // Total Geral / TOTAL
            if (preg_match('/(?:TOTAL|Total\s+Geral)\s*:?\s*([\d.,]+)\s*€?/i', $trimmed, $m)) {
                $documentTotal = $m[1];
            }

            // Linhas de IVA: "IVA (23%) : 194,84 €"
            if (preg_match('/(?:IVA|VAT)\s*(?:\(?\s*(\d+)\s*%?\s*\)?)?\s*:?\s*([\d.,]+)\s*€?/i', $trimmed, $m)) {
                $taxRate = !empty($m[1]) ? (float) $m[1] : 23.0;
                $documentTaxes[] = [
                    'rate' => $taxRate,
                    'amount' => $m[2],
                ];
            }
        }

        Log::info('[PO-OCR] Document totals extracted.', [
            'requestId' => $requestId,
            'subtotal' => $documentSubtotal,
            'total' => $documentTotal,
            'taxes' => count($documentTaxes),
        ]);

        return [
            'supplier' => $supplier,
            'lines' => $parsedLines,
            'documentDate' => $supplier['dataEncomenda'] ?? null,
            'documentSubtotal' => $documentSubtotal,
            'documentTotal' => $documentTotal,
            'documentTaxes' => $documentTaxes,
        ];
    }

    /**
     * Extrai linhas tabulares (artigos) a partir do texto OCR.
     *
     * Agora com deteção de cabeçalhos de colunas — procura por uma linha
     * de cabeçalho com nomes de colunas conhecidos e usa essa estrutura
     * para fazer o parsing das linhas de dados.
     *
     * Estratégia:
     *   1. Deteta cabeçalhos (Ref., Descrição, Qtd, Preço, Valor, etc.)
     *   2. Para cada linha de dados, distribui os tokens pelas colunas
     *   3. Fallback para os parsers legados se não houver cabeçalhos
     */
    private function extractPOTabularLines(string $text): array
    {
        $lines = explode("\n", $text);
        $results = [];

        // ── Passo 0: Detetar cabeçalhos de colunas ──
        $columnMap = $this->detectColumnHeaders($lines);
        $headerIdx = $columnMap['headerLineIdx'] ?? null;
        $hasColumnStruct = ($columnMap['columnCount'] ?? 0) >= 2;

        foreach ($lines as $idx => $raw) {
            $line = trim($raw);
            if (empty($line)) {
                continue;
            }

            // Saltar linha de cabeçalho e separadores
            if ($headerIdx !== null && $idx === $headerIdx) {
                continue;
            }
            if (preg_match('/^[\s\-_=]{5,}$/u', $line)) {
                continue;
            }

            // ── Filtro precoce: saltar linhas obviamente não-produto ──
            if ($this->isLineExcluded($line)) {
                continue;
            }

            // ── Passo 1: Tenta o parser dedicado de colchetes/pipes ──
            $bracketResult = $this->tryParseBracketPipeLine($line);
            if ($bracketResult !== null && !empty($bracketResult['descricao'])) {
                $results[] = $bracketResult;
                continue;
            }

            // ── Passo 2: Tenta parser baseado em cabeçalhos detetados ──
            if ($hasColumnStruct) {
                $colResult = $this->parseDataLineWithColumns($line, $columnMap);
                if ($colResult !== null) {
                    $desc = $colResult['descricao'] ?? '';
                    $code = $colResult['codigo'] ?? '';
                    $descWords = count(array_filter(explode(' ', $desc)));
                    $tokenCount = count(preg_split('/\s+/', $line, -1, PREG_SPLIT_NO_EMPTY));
                    // Quality check: reject low-quality column parser results
                    // so legacy parsers get a chance (handles alphanumeric codes, etc.)
                    $isLowQuality = empty($code) && $descWords <= 2 && $tokenCount >= 5;
                    if (!empty($colResult['descricao']) && !$isLowQuality) {
                        $results[] = $colResult;
                        continue;
                    }
                }
            }

            // ── Passo 3: Padrão com números no fim da linha ──
            // Ex: "ART-001 Parafuso Inox M8x20 10 2,50"
            // Ex: "1001 Arroz Agulha Cacarola kg 20 20 1,15"
            // Ex: "Parafuso Inox M8x20 10 x 2,50"
            // Remove € de toda a linha ANTES de qualquer parsing (ex: "1,15€" -> "1,15")
            $lineNoEuro = str_replace('€', '', $line);

            // Extrai IVA da linha antes de o remover (ex: "23%", "6%")
            $trailingIva = null;
            if (preg_match('/\b(\d{1,2})\s*%\b/u', $lineNoEuro, $ivaMatch)) {
                $trailingIva = $this->normalizeNumber($ivaMatch[1]);
            }

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
                        // ou um total grande onde a qtd foi confundida com total
                        $num0 = $this->normalizeNumber($trailingNumbers[0]);
                        $num1 = $this->normalizeNumber($trailingNumbers[1]);
                        $num2 = $this->normalizeNumber($trailingNumbers[2]);
                        $secondLastNum = $num1;
                        $lastNum = $num2;

                        // Sanity check: if the "price" (num1) > "qty" (num0) * 10,
                        // the numbers are likely misread; try alternative permutation
                        if ($num1 > $num0 * 10 && $num0 > 0 && $num2 > $num1) {
                            // [total, qty, price] → use num1 as qty, num2 as price
                            $qtyStr = $trailingNumbers[1];
                            $priceStr = $trailingNumbers[2];
                        } elseif ($secondLastNum > 0 && $lastNum / $secondLastNum > 1.5) {
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
                        'iva' => $trailingIva,
                    ];
                    continue;
                }
            }

            // ── Passo 4: Acumula descrição para linhas multi-linha ──
            // (apenas se não tiver estrutura de colunas detetada)
            if (!$hasColumnStruct && preg_match('/^[\s\S]{3,60}$/u', $line) && !$this->isLineExcluded($line)) {
                // Já não usamos currentDescription — descartamos linhas sem parse completo
            }
        }

        // ── Pós-processamento: filtrar linhas duvidosas ──
        $results = array_values(array_filter($results, function ($line) {
            $desc = $line['descricao'] ?? '';
            // Remove linhas com descrições que são apenas vírgulas, pontos ou artefactos
            $descClean = trim(preg_replace('/[,\s.\[\]|()\-]+/u', '', $desc));
            if (mb_strlen($descClean) < 3) {
                return false;
            }

            // Remove linhas cuja descrição contém fragmentos de resumo/total
            if (preg_match('/\b(?:iva|total|subtotal|sub\s*total|obs(?:erva[çc])?|emiss[ãa]o|documento|nota\s+de\s+encomenda|entrega\s+prevista)\b/i', $desc)) {
                return false;
            }

            // Remove linhas cuja descrição contém padrões de morada
            if (preg_match('/\b(?:Rua|Av\.|Avenida|Travessa|Largo|Pra[çc]a|Estrada|Lote|N[ºo]|NIF)\b/i', $desc)) {
                $digitsOnly = preg_replace('/[^0-9]/', '', $desc);
                if (preg_match('/\d{9}/', $digitsOnly) || preg_match('/\d{4}-\d{3}/', $desc)) {
                    return false;
                }
            }

            // Remove linhas cuja descrição é apenas números + caracteres especiais
            $alphaOnly = preg_replace('/[^A-Za-zÀ-ÿ]/u', '', $desc);
            if (mb_strlen($alphaOnly) < 4 && preg_match('/\d{3,}/', preg_replace('/[^0-9]/', '', $desc))) {
                return false;
            }

            // Sanity check: quantity > 100 AND unitPrice > 100 is likely a total/aggregate line
            $qty = (float) ($line['quantidade'] ?? 0);
            $price = (float) ($line['precoUnitario'] ?? 0);
            if ($qty > 100 && $price > 100) {
                return false;
            }

            return true;
        }));

        return $results;
    }

    /**
     * Tenta extrair uma linha de artigo no formato colchetes/pipes de tabela de fornecedor.
     *
     * Formatos suportados:
     *   "0122 [Batata Monalisa Sac 10kg | 5[Cx] 8,60] 6% | 42,50)"
     *   "0344 [Tomate Chucha Amad. 25KG | 25[KG| 1,85] 6% | 46,25|"
     *   "0551 Salsa Frisada Molho 20] UN 6%"
     *
     * @return array|null Linha estruturada ou null se não reconhecer o formato
     */
    private function tryParseBracketPipeLine(string $line): ?array
    {
        $stripped = trim($line);

        if (!preg_match('/^([A-Za-z0-9]+(?:-[A-Za-z0-9]+)?)\s+/u', $stripped, $codeMatch)) {
            return null;
        }
        $code = $codeMatch[1];
        $rest = trim(substr($stripped, strlen($codeMatch[0])));

        $hasPipe = strpos($rest, '|') !== false;
        $hasBracketClose = strpos($rest, ']') !== false;

        if (!$hasPipe && !$hasBracketClose) {
            return null;
        }

        if ($hasPipe) {
            $pipePos = strpos($rest, '|');
            $descRaw = trim(substr($rest, 0, $pipePos));
            $dataRaw = trim(substr($rest, $pipePos + 1));
        } else {
            if (preg_match('/^(.+?)\s+(\d+)\s*\]/u', $rest, $bracketMatch)) {
                $descRaw = $bracketMatch[1];
                $dataRaw = substr($rest, strlen($bracketMatch[0]));
            } else {
                return null;
            }
        }

        $desc = ltrim($descRaw, '[');
        $desc = rtrim($desc);
        $desc = $this->sanitizeDescription($desc);
        if (empty($desc)) {
            return null;
        }

        $dataClean = preg_replace('/\[[A-Za-z|]+\]?/u', ' ', $dataRaw);
        $dataClean = str_replace([']', '|', ')'], ' ', $dataClean);
        preg_match_all('/\d+(?:[.,]\d+)?/u', $dataClean, $numberMatches);
        $numbersRaw = $numberMatches[0] ?? [];

        if (count($numbersRaw) < 2) {
            if (count($numbersRaw) === 1) {
                return [
                    'codigo' => $code,
                    'descricao' => $desc,
                    'quantidade' => $this->normalizeNumber($numbersRaw[0]),
                    'precoUnitario' => 0,
                ];
            }
            return null;
        }

        $numbers = array_map(fn($n) => $this->normalizeNumber($n), $numbersRaw);
        $vatRates = [6.0, 13.0, 23.0];

        // Extrai IVA do token bruto (ex: "6%", "13%" no dataRaw)
        $iva = null;
        if (preg_match('/(\d{1,2})\s*%/u', $dataRaw, $ivaMatch)) {
            $ivaCandidate = (float) $ivaMatch[1];
            if ($ivaCandidate > 0 && $ivaCandidate <= 100) {
                $iva = $ivaCandidate;
            }
        }

        $filtered = [];
        foreach ($numbers as $n) {
            if (in_array($n, $vatRates, true) && count($filtered) >= 1) {
                continue;
            }
            if (count($filtered) >= 2 && ($filtered[0] ?? 0) > 0 && ($filtered[1] ?? 0) > 0) {
                $expectedTotal = $filtered[0] * $filtered[1];
                if ($expectedTotal > 0 && abs($n - $expectedTotal) / $expectedTotal < 0.15) {
                    continue;
                }
            }
            $filtered[] = $n;
        }

        $qty = $filtered[0] ?? $this->normalizeNumber($numbersRaw[0]);
        $priceCandidate = $filtered[1] ?? null;
        if ($priceCandidate !== null && in_array($priceCandidate, $vatRates, true)) {
            $priceCandidate = null;
        }
        $price = $priceCandidate ?? (count($numbersRaw) > 1 ? $this->normalizeNumber($numbersRaw[1]) : 0);

        return [
            'codigo' => $code,
            'descricao' => $desc,
            'quantidade' => $qty,
            'precoUnitario' => $price,
            'iva' => $iva,
        ];
    }

    /**
     * Deteta uma linha de cabeçalho de colunas no texto OCR.
     *
     * Procura por padrões como:
     *   "Ref.  Designação  Quant.  Pr. Unit.  Valor"
     *   "Cód.  Descrição   Qtd Enc  Qtd Ent  Preço  Total"
     *
     * @param  string[]  $lines
     * @return array{codigoCol:int|null, descricaoCol:int|null, quantidadeCol:int|null, precoUnitarioCol:int|null, totalCol:int|null, ivaCol:int|null, unidadeCol:int|null, columnCount:int, headerLineIdx:int|null}
     */
    private function detectColumnHeaders(array $lines): array
    {
        $columnDefs = [
            'codigo' => [
                '/\bRef\.?\b/i', '/\bReferencia\b/i', '/\bC[oó]d\.?\b/i',
                '/\bC[oó]digo\b/i', '/\bCod\.?\b/i', '/\bArt\.?\b/i',
                '/\bArtigo\b/i', '/\bRef\b/i',
            ],
            'descricao' => [
                '/\bDescri[cç][aã]o\b/i', '/\bDesigna[cç][aã]o\b/i',
                '/\bArtigo\b/i', '/\bProduto\b/i', '/\bDesc\.?\b/i',
                '/\bDesign\.?\b/i',
            ],
            'quantidade' => [
                '/\bQtd\.?\s*Enc/i', '/\bQuant\.?\s*Enc/i', '/\bQtd\.?\b/i',
                '/\bQuant\.?\b/i', '/\bQuantidade\b/i', '/\bQty\b/i',
                '/\bQtd\b/i', '/\bUnid\.?\b/i', '/\bUn\.?\b/i',
                '/\bUnidades?\b/i',
            ],
            'precoUnitario' => [
                '/\bPr\.?\s*Unit\.?\b/i', '/\bPre[cç]o\s*Unit\.?\b/i',
                '/\bP\.?\s*Unit\.?\b/i', '/\bValor\s*Unit\.?\b/i',
                '/\bPre[cç]o\b/i', '/\bPr\.?\b/i',
            ],
            'total' => [
                '/\bTotal\b/i', '/\bValor\b/i', '/\bImport\.?\b/i',
                '/\bImport[aâ]ncia\b/i', '/\bL[ií]quido\b/i',
            ],
            'iva' => [
                '/\bIVA\b/i', '/\bTaxa\b/i', '/\bIVA\s*%/i', '/\b%\s*IVA/i',
            ],
            'unidade' => [
                '/\bUn\.?\b/i', '/\bUN\b/i', '/\bUnidade\b/i', '/\bMedida\b/i',
            ],
        ];

        $separatorPattern = '/^[\s\-_=]{5,}$/u';

        foreach ($lines as $idx => $line) {
            $stripped = trim($line);
            if (empty($stripped)) {
                continue;
            }
            if (preg_match($separatorPattern, $stripped)) {
                continue;
            }
            // Pula linhas com muitos números (provavelmente dados, não cabeçalho)
            $digitCount = preg_match_all('/\d/', $stripped);
            $totalLen = mb_strlen($stripped) ?: 1;
            if ($digitCount / $totalLen > 0.3) {
                continue;
            }

            $foundColumns = [];
            foreach ($columnDefs as $key => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $stripped, $m, PREG_OFFSET_CAPTURE)) {
                        $pos = $m[0][1];
                        // Evitar sobreposição com colunas já encontradas
                        $overlap = false;
                        foreach ($foundColumns as $existing) {
                            $existingEnd = $existing['position'] + mb_strlen($existing['match']);
                            $newEnd = $pos + mb_strlen($m[0][0]);
                            if ($pos < $existingEnd && $newEnd > $existing['position']) {
                                $overlap = true;
                                break;
                            }
                        }
                        if (!$overlap) {
                            $foundColumns[$key] = [
                                'position' => $pos,
                                'match' => $m[0][0],
                            ];
                        }
                        break;
                    }
                }
            }

            // Precisamos de pelo menos 2 colunas reconhecidas
            if (count($foundColumns) >= 2) {
                // Ordena por posição
                uasort($foundColumns, fn($a, $b) => $a['position'] <=> $b['position']);
                $sortedKeys = array_keys($foundColumns);

                $result = [
                    'codigoCol' => array_search('codigo', $sortedKeys, true),
                    'descricaoCol' => array_search('descricao', $sortedKeys, true),
                    'quantidadeCol' => array_search('quantidade', $sortedKeys, true),
                    'precoUnitarioCol' => array_search('precoUnitario', $sortedKeys, true),
                    'totalCol' => array_search('total', $sortedKeys, true),
                    'ivaCol' => array_search('iva', $sortedKeys, true),
                    'unidadeCol' => array_search('unidade', $sortedKeys, true),
                    'columnCount' => count($foundColumns),
                    'headerLineIdx' => $idx,
                ];

                // Converter false para null
                foreach (['codigoCol', 'descricaoCol', 'quantidadeCol', 'precoUnitarioCol', 'totalCol', 'ivaCol', 'unidadeCol'] as $col) {
                    if ($result[$col] === false) {
                        $result[$col] = null;
                    }
                }

                Log::info('[PO-OCR] Cabeçalhos de colunas detetados.', [
                    'lineIdx' => $idx,
                    'columns' => $sortedKeys,
                    'count' => count($foundColumns),
                ]);

                return $result;
            }
        }

        return [
            'codigoCol' => null, 'descricaoCol' => null, 'quantidadeCol' => null,
            'precoUnitarioCol' => null, 'totalCol' => null, 'ivaCol' => null,
            'unidadeCol' => null, 'columnCount' => 0, 'headerLineIdx' => null,
        ];
    }

    /**
     * Faz o parsing de uma linha de dados usando a estrutura de colunas detetada.
     *
     * @param  string  $line  Linha de dados
     * @param  array   $colMap  Mapa de colunas (do detectColumnHeaders)
     * @return array|null  Linha estruturada ou null se não foi possível fazer parse
     */
    private function parseDataLineWithColumns(string $line, array $colMap): ?array
    {
        $stripped = trim($line);
        if (empty($stripped)) {
            return null;
        }

        // Divide a linha em tokens (palavras e números)
        if (!preg_match_all('/(?:\[?[A-Za-zÀ-ÿ0-9.,%\/+)-]+\]?)/u', $stripped, $tokenMatches)) {
            return null;
        }
        $tokens = array_map('trim', $tokenMatches[0]);
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));
        if (empty($tokens)) {
            return null;
        }

        $totalCols = $colMap['columnCount'] ?? 0;
        if ($totalCols < 2) {
            return null;
        }

        $safeGet = function (?int $colIdx) use ($tokens) {
            if ($colIdx !== null && isset($tokens[$colIdx])) {
                return $tokens[$colIdx];
            }
            return null;
        };

        $codeToken = $safeGet($colMap['codigoCol'] ?? null);
        $descToken = $safeGet($colMap['descricaoCol'] ?? null);
        $qtyToken = $safeGet($colMap['quantidadeCol'] ?? null);
        $priceToken = $safeGet($colMap['precoUnitarioCol'] ?? null);

        // Código: aceita códigos puramente numéricos (2-4 dígitos) e alfanuméricos (ex: ART-011, FD-901)
        $codigo = '';
        if ($codeToken && preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)?$/', $codeToken)) {
            $codigo = $codeToken;
        }

        // Descrição: pode ocupar um intervalo de tokens até ao primeiro token numérico
        // Os índices do cabeçalho identificam as COLUNAS, não os limites exatos dos tokens —
        // as descrições nos dados ocupam múltiplos tokens, por isso varremos para a frente
        // até encontrar o primeiro token puramente numérico (quantidade ou preço).
        $descricao = '';
        $codeIdx = $colMap['codigoCol'] ?? null;
        $descIdx = $colMap['descricaoCol'] ?? null;
        $qtyIdx = $colMap['quantidadeCol'] ?? null;
        $priceIdx = $colMap['precoUnitarioCol'] ?? null;

        $descStart = $descIdx ?? (($codeIdx !== null) ? $codeIdx + 1 : 0);
        $descEnd = count($tokens);
        if ($descStart < count($tokens)) {
            for ($i = $descStart + 1; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                if (preg_match('/^\d+(?:[.,]\d+)?$/', $token)) {
                    $descEnd = $i;
                    break;
                }
            }
        }

        if ($descStart < $descEnd) {
            $descParts = array_slice($tokens, $descStart, $descEnd - $descStart);
            // Filtra tokens que são apenas artefactos de tabela
            $descParts = array_filter($descParts, function ($t) {
                $tUpper = strtoupper(trim($t, '[]|()'));
                return !in_array($tUpper, ['', '6%', '13%', '23%', '6', '13', '23'], true)
                    && !preg_match('/^\d+[%]?$/', $t)
                    && !in_array($t, ['[', ']', '|', '(', ')'], true);
            });
            $descricao = implode(' ', $descParts);
            $descricao = $this->sanitizeDescription($descricao);
        }

        // Quantidade e preço
        $quantidade = $qtyToken ? $this->normalizeNumber($qtyToken) : 0;
        $precoUnitario = $priceToken ? $this->normalizeNumber($priceToken) : 0;

        // Verifica se priceToken é uma taxa de IVA e não preço
        $vatRates = [6.0, 13.0, 23.0];
        if (in_array($precoUnitario, $vatRates, true) && $priceToken && preg_match('/^\d{1,2}\s*%?$/', $priceToken)) {
            $precoUnitario = 0;
        }

        // Extrai IVA varrendo TODOS os tokens (não usa índice fixo — descrições com
        // múltiplos tokens deslocam as colunas de dados para além da posição do cabeçalho)
        $iva = null;
        foreach ($tokens as $token) {
            if (preg_match('/^(\d{1,2})\s*%$/', $token, $ivaMatch)) {
                $iva = (float) $ivaMatch[1];
                break;
            }
        }

        // Deteção de unidade
        $unidade = null;
        $unitKeywords = ['KG', 'CX', 'UN', 'G', 'L', 'ML', 'LT', 'M', 'PCT', 'SAC', 'MOLHO', 'EMB', 'PAR'];
        foreach ($tokens as $token) {
            $upper = strtoupper(trim($token, '[]|()'));
            if (in_array($upper, $unitKeywords, true)) {
                $unidade = $upper;
                break;
            }
        }

        // Se não temos descrição nem código, descartamos
        if (empty($descricao) && empty($codigo)) {
            return null;
        }

        // Filtra linhas de cabeçalho/total que passaram
        $descClean = trim(preg_replace('/[,\s.\[\]|()\-]+/u', '', $descricao));
        if (mb_strlen($descClean) < 3) {
            return null;
        }

        return [
            'codigo' => $codigo,
            'descricao' => $descricao,
            'quantidade' => $quantidade,
            'precoUnitario' => $precoUnitario,
            'unidade' => $unidade,
            'iva' => $iva,
        ];
    }

    /**
     * Verifica se uma linha OCR deve ser excluída do parsing de artigos.
     */
    private function isLineExcluded(string $line): bool
    {
        $line = trim($line);
        if (empty($line)) {
            return true;
        }

        // Exclude by keyword patterns — includes garbled OCR fragments (sl, ções, etc.)
        if ((bool) preg_match(
            '/(?:total|iva|subtotal|sub\s*total|eur|nif|contribuinte|data|página|page|observaç|condiç|obs\.|'
            . 'nota\s+de\s+encomenda|entrega\s+prevista|documento\s+n[ºo]|'
            . 'data\s+emiss[ãa]o|c[óo]digo\s+artigo|descri[çc][ãa]o|fornecedor|'
            . 'cliente|\\bNIF\\b|\\bsl\\b|\\bobs\\b|\\bções\\b|\\bs\/\\s*IVA\\b)/i',
            $line
        )) {
            return true;
        }

        // Exclude lines with embedded NIF (9 consecutive digits) and address context
        $digitsOnly = preg_replace('/[^0-9]/', '', $line);
        if (strlen($digitsOnly) >= 9 && preg_match('/\d{9}/', $digitsOnly)) {
            if (preg_match('/\b(?:nif|contribuinte|Rua|Av\.|Avenida|cliente|morada)\b/i', $line)) {
                return true;
            }
        }

        // Exclude URL / file path patterns
        if (preg_match('/^(?:file|http)s?[:\/]/i', $line)) {
            return true;
        }

        // Exclude page number patterns (e.g., "1/1")
        if (preg_match('/^\d+\s*\/\s*\d+\s*$/', $line)) {
            return true;
        }

        // ── DIAGNOSTIC FIX: Exclude short garbage fragments ──
        // Lines with < 3 meaningful alphanumeric characters are noise
        $alphaOnly = preg_replace('/[^A-Za-zÀ-ÿ0-9]/u', '', $line);
        if (mb_strlen($alphaOnly) < 3) {
            return true;
        }

        // Exclude standalone date fragments (e.g., "/05/26", "24/05/26")
        if (preg_match('#^\d{1,2}\s*[/\-]\s*\d{1,2}\s*(?:[/\-]\s*\d{2,4})?\s*$#', $line)) {
            return true;
        }

        // Exclude standalone units of measure
        if (preg_match('/^(?:un|kg|g|l|ml|lt|cx|pct|m|cm|mm|hrs|hr|saco?|molho|dose|emb|par)$/i', $line)) {
            return true;
        }

        // Exclude standalone VAT percentages (e.g., "6%", "23%")
        if (preg_match('/^\d{1,2}\s*%$/', $line)) {
            return true;
        }

        // Exclude lines that are just a phone number (9+ digits with optional spaces/+)
        if (preg_match('/^\+?\d[\d\s]{7,}$/', $line) && preg_match_all('/\d/', $line) >= 8) {
            return true;
        }

        // Exclude lines starting with a large number (>100) followed by fiscal/header terms
        if (preg_match('/^\s*(\d{3,}(?:[.,]\d+)?)\s*[€]?\s*(?:IVA|Total|Subtotal|$|\|)/i', $line)) {
            return true;
        }

        // Exclude lines with code patterns that match known header/address words
        if (preg_match('/^(?:NOTA|DATA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[ÃA]O|'
            . 'C[ÓO]DIGO|DESCRI[ÇC][ÃA]O|QTD|TOTAL|FORNECEDOR|NIF|TEL|TELEFONE|'
            . 'CONTRIBUINTE|RUA|AV\.|AVENIDA|P[ÁA]GINA|OBS|IVA|EUR|ARTIGO|'
            . 'CLIENTE|DESIGNA[ÇC][ÃA]O|UNIDADE|MEDIDA|REFER[ÊE]NCIA)\b$/i', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Enriquece os dados interpretados com informação da base de dados.
     *
     * Para cada artigo, tenta resolver o produto existente ou cria um novo.
     * O mesmo para o fornecedor.
     */
    private function enrichPurchaseOrderDataWithDatabase(array $parsedData, ?string $requestId = null, bool $createIfMissing = false): array
    {
        // --- Fornecedor ---
        $supplierResult = $this->findOrCreateSupplierFromOcr($parsedData['supplier'] ?? [], $createIfMissing);
        $supplierModel = $supplierResult['model'] ?? null;

        $enrichedSupplier = [
            'code' => $supplierModel ? (string) $supplierModel->code : '',
            'name' => $supplierModel ? $supplierModel->name : ($parsedData['supplier']['nome'] ?? ''),
            'nif' => $supplierModel ? $supplierModel->nif : ($parsedData['supplier']['nif'] ?? ''),
            'found' => $supplierResult['found'] ?? false,
        ];

        // --- Linhas ---
        $enrichedLines = [];
        $enabledLineSubtotals = 0;

        foreach (($parsedData['lines'] ?? []) as $i => $line) {
            $productResult = $this->findOrCreateProductFromOcr($line, $createIfMissing);
            $productModel = $productResult['model'] ?? null;

            $productCode = $productModel ? (string) $productModel->code : ($line['codigo'] ?? '');
            $rawOcrDesc = (string) ($line['descricao'] ?? '');
            $sanitizedOcr = $this->sanitizeDescription($rawOcrDesc);

            if ($productModel) {
                $sanitizedDb = $this->sanitizeDescription((string) $productModel->description);
                $description = $sanitizedDb ?: ($sanitizedOcr ?: $rawOcrDesc);
            } else {
                $description = $sanitizedOcr ?: $rawOcrDesc;
            }

            // Pula linhas sem código nem descrição (ruído do OCR)
            if (empty(trim($productCode)) && empty(trim($description))) {
                continue;
            }

            // ── Quality threshold: skip garbage descriptions ──
            $descAlpha = preg_replace('/[^A-Za-zÀ-ÿ0-9]/u', '', $description);
            if (mb_strlen($descAlpha) < 3 && empty(trim($productCode))) {
                Log::info('[PO-OCR] Skipping low-quality line (desc too short).', [
                    'description' => $description,
                    'code' => $productCode,
                ]);
                continue;
            }

            // Skip lines where description looks like a file path or URL
            if (preg_match('#^(?:file|http)s?[:/]|^[A-Z]:[\\\\/]#i', $description)) {
                Log::info('[PO-OCR] Skipping line with file path / URL description.', [
                    'description' => $description,
                ]);
                continue;
            }

            // Skip lines where description is just numbers (price/qty fragments)
            if (preg_match('/^[\d.,\s]+$/', $description) && empty(trim($productCode))) {
                continue;
            }

            $quantity = $this->normalizeNumber($line['quantidade'] ?? 1);
            $unitPrice = $this->normalizeNumber($line['precoUnitario'] ?? 0);

            // ── Semantic validation: skip lines that look like non-products (only during preview) ──
            if (!$createIfMissing && $this->isDescriptionLikelyNonProduct($description, $productCode)) {
                Log::info('[PO-OCR] Skipping line with non-product description.', [
                    'description' => $description,
                    'code' => $productCode,
                ]);
                continue;
            }

            $lineIva = isset($line['iva']) ? (float) $line['iva'] : null;
            $taxRateCode = $productModel ? (int) $productModel->taxRateCode : $this->resolveTaxRateFromOcr($lineIva);
            $taxRatePercent = (float) TaxRate::where('taxRateCode', $taxRateCode)->value('taxRate') ?? 0;

            $productFamily = $productModel ? $productModel->family : ($createIfMissing ? $this->ensureDefaultFamily() : config('purchaseorder.ocr.default_family', 'GERAL'));
            $productUnit = $productModel ? $productModel->unit : ($createIfMissing ? $this->resolveUnitFromOcr($line['unidade'] ?? '') : config('purchaseorder.ocr.default_unit', 'UN'));

            $enrichedLines[] = [
                'productCode' => $productCode,
                'description' => $description,
                'productFamily' => $productFamily,
                'productUnit' => $productUnit,
                'taxRateCode' => $taxRateCode,
                'taxRate' => $taxRatePercent,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'found' => $productResult['found'] ?? false,
                'enabled' => true,
                'ocrRaw' => [
                    'codigo' => $line['codigo'] ?? '',
                    'descricao' => $rawOcrDesc,
                    'quantidade' => $line['quantidade'] ?? null,
                    'precoUnitario' => $line['precoUnitario'] ?? null,
                    'unidade' => $line['unidade'] ?? null,
                    'iva' => $line['iva'] ?? null,
                ],
            ];

            $enabledLineSubtotals += $quantity * $unitPrice;
        }

        // ── Subtotal cross-validation ──
        $validation = $this->buildSubtotalValidation($parsedData, $enrichedLines, $enabledLineSubtotals);

        Log::info('[PO-OCR] Dados enriquecidos com BD.', [
            'requestId' => $requestId,
            'supplier' => $enrichedSupplier,
            'linesCount' => count($enrichedLines),
            'validation' => $validation,
        ]);

        return [
            'supplier' => $enrichedSupplier,
            'lines' => $enrichedLines,
            'validation' => $validation,
            'documentDate' => $parsedData['documentDate'] ?? null,
            'documentNumber' => $parsedData['documentNumber'] ?? null,
        ];
    }

    /**
     * Verifica se uma descrição de linha extraída parece ser algo que NÃO é um produto
     * (morada, NIF, total, IVA, observações, etc.).
     */
    private function isDescriptionLikelyNonProduct(string $description, string $productCode): bool
    {
        $desc = mb_strtolower($description, 'UTF-8');

        // Address patterns
        if (preg_match('/\b(?:rua|av\.|avenida|travessa|largo|pra[çc]a|estrada|lote|andar|n[ºo])\b/i', $desc)) {
            $stripped = preg_replace('/[^a-z0-9]/', '', $desc);
            if (preg_match('/\d{9}/', $stripped) || preg_match('/\d{4}-\d{3}/', $desc)) {
                return true;
            }
        }

        // NIF: 9 consecutive digits. Skip if line has meaningfully more text.
        $digitsOnly = preg_replace('/[^0-9]/', '', $desc);
        if (preg_match('/\d{9}/', $digitsOnly) && mb_strlen(preg_replace('/[^a-z]/', '', $desc)) < 8) {
            return true;
        }

        // Fiscal/administrative terms
        if (preg_match('/\b(?:IVA|VAT|total(?:\s+geral)?|subtotal|emiss[ãa]o|documento|nota\s+de\s+encomenda|entrega\s+prevista)\b/i', $desc)) {
            return true;
        }

        // Phone numbers (9+ digits with spaces)
        if (preg_match('/\d[\d\s]{8,}/', $desc) && preg_match_all('/\d/', $desc) >= 9 && mb_strlen($desc) < 40) {
            return true;
        }

        // NIF label pattern (e.g., "NIF: 502345671")
        if (preg_match('/\bNIF\b/i', $desc) && preg_match('/\d{9}/', $digitsOnly)) {
            return true;
        }

        return false;
    }

    /**
     * Calcula a validação cruzada de sub-totais: compara o subtotal extraído do
     * documento com o subtotal calculado a partir das linhas.
     */
    private function buildSubtotalValidation(array $parsedData, array $enrichedLines, float $calculatedSubtotal): array
    {
        $extractedSubtotal = $this->normalizeNumber($parsedData['documentSubtotal'] ?? '0');
        $extractedTotal = $this->normalizeNumber($parsedData['documentTotal'] ?? '0');
        $extractedTaxes = $parsedData['documentTaxes'] ?? [];

        $validation = [];

        if ($extractedSubtotal > 0 && $calculatedSubtotal > 0) {
            $disc = abs($calculatedSubtotal - $extractedSubtotal);
            $discPct = $extractedSubtotal > 0 ? ($disc / $extractedSubtotal) * 100 : 0;

            if ($discPct < 2) {
                $status = 'ok';
            } elseif ($discPct < 10) {
                $status = 'warning';
            } else {
                $status = 'error';
            }

            $validation['subtotal'] = [
                'extracted' => round($extractedSubtotal, 2),
                'calculated' => round($calculatedSubtotal, 2),
                'discrepancy' => round($disc, 2),
                'discrepancyPercent' => round($discPct, 2),
                'status' => $status,
            ];
        }

        // Calculated total = subtotal + sum of tax amounts extracted
        if ($extractedTotal > 0 && $calculatedSubtotal > 0) {
            $totalTaxes = 0;
            foreach ($extractedTaxes as $tax) {
                $totalTaxes += $this->normalizeNumber($tax['amount'] ?? '0');
            }
            $calculatedTotal = $calculatedSubtotal + $totalTaxes;

            $disc = abs($calculatedTotal - $extractedTotal);
            $discPct = $extractedTotal > 0 ? ($disc / $extractedTotal) * 100 : 0;

            if ($discPct < 2) {
                $status = 'ok';
            } elseif ($discPct < 10) {
                $status = 'warning';
            } else {
                $status = 'error';
            }

            $validation['total'] = [
                'extracted' => round($extractedTotal, 2),
                'calculated' => round($calculatedTotal, 2),
                'discrepancy' => round($disc, 2),
                'discrepancyPercent' => round($discPct, 2),
                'status' => $status,
            ];
        }

        // Include extracted taxes for frontend display
        if (!empty($extractedTaxes)) {
            $validation['taxes'] = $extractedTaxes;
        }

        return $validation;
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
        $original = $value;
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

        // 10. Remove artefactos de vírgulas internas (ex: ", ," de bordas de tabela mal lidas pelo OCR)
        $value = preg_replace('/(?:\s*,\s*){2,}/u', ' ', $value);
        // 10b. Remove artefactos de traços/hifens (ex: "---" ou "- - -" de bordas de tabela mal lidas)
        //      Também cobre variantes Unicode: – (U+2013), — (U+2014), − (U+2212)
        $value = preg_replace('/(?:[-–—−]+\s*){2,}/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim($value);

        // 11. Remove artefactos comuns que sobram (apenas pontuação no início/fim)
        $value = preg_replace('/^[–—−\-.,\s;|\])]+/u', '', $value);
        $value = preg_replace('/[–—−\-.,\s;|\])]+$/u', '', $value);
        // Se a descrição ficou só com pontuação/espaços, retorna vazia
        $clean = preg_replace('/[^A-Za-z0-9À-ÿ]/u', '', $value);
        if (mb_strlen($clean) < 2) {
            return '';
        }

        // 12. Limita a 200 caracteres; se exceder, corta para 197 e acrescenta "..."
        if (mb_strlen($value) > 200) {
            $value = mb_substr($value, 0, 197) . '...';
        }

        $result = trim($value);
        if ($result !== trim($original)) {
            \Illuminate\Support\Facades\Log::debug('[PO-OCR] sanitizeDescription cleaned', [
                'original' => $original,
                'result' => $result,
            ]);
        }
        return $result;
    }
}
