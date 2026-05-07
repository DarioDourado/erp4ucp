<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Supplier;
use App\Models\Product;
use App\Models\Family;
use App\Models\TaxRate;
use App\Models\PurchaseOrderC;
use App\Models\PurchaseOrderD;
use App\Models\StockMovement;

use Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function PurchaseOrderAdd()
    {
        $suppliers = $this->getSuppliers();
        $families = $this->getFamilies();
        $products = $this->getProductsForForm();
        $nextPONumber = ((int) PurchaseOrderC::max('pONumber')) + 1;
        $initialLines = $this->normalizeLinesForForm(collect(old('lines', [])));

        return view('backend.purchaseOrder.purchaseOrderC_add', compact(
            'suppliers',
            'families',
            'products',
            'nextPONumber',
            'initialLines'
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
}
