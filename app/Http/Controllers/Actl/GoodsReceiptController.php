<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptC;
use App\Models\GoodsReceiptD;
use App\Models\Product;
use App\Models\PurchaseOrderC;
use App\Models\PurchaseOrderD;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoodsReceiptController extends Controller
{
    public function GoodsReceiptAll()
    {
        $goodsReceipts = GoodsReceiptC::with(['supplierLink', 'purchaseOrderLink'])
            ->orderBy('gRDate', 'DESC')
            ->orderBy('gRNumber', 'DESC')
            ->get();

        return view('backend.goodsReceipt.goodsReceipt_all', compact('goodsReceipts'));
    }

    public function GoodsReceiptAdd(Request $request)
    {
        $suppliers = $this->getSuppliers();
        $nextGRNumber = ((int) GoodsReceiptC::max('gRNumber')) + 1;
        $selectedSupplierCode = (int) $request->query('supplierCode', old('supplierCode', 0));
        $selectedPurchaseOrderId = (int) $request->query('purchaseOrderId', old('purchaseOrderId', 0));
        $supplierGuideNumber = (string) $request->query('supplierGuideNumber', old('supplierGuideNumber', ''));

        $purchaseOrderSelectionData = $this->getPurchaseOrderSelectionData($selectedPurchaseOrderId, $selectedSupplierCode);

        return view('backend.goodsReceipt.goodsReceipt_add', [
            'mode' => 'create',
            'receipt' => null,
            'suppliers' => $suppliers,
            'nextGRNumber' => $nextGRNumber,
            'selectedSupplierCode' => $selectedSupplierCode,
            'supplierGuideNumber' => $supplierGuideNumber,
            'selectedPurchaseOrder' => $purchaseOrderSelectionData['purchaseOrder'],
            'lineRows' => $purchaseOrderSelectionData['lineRows'],
            'taxSummary' => $purchaseOrderSelectionData['taxSummary'],
            'totals' => $purchaseOrderSelectionData['totals'],
        ]);
    }

    public function GoodsReceiptStore(Request $request)
    {
        $validated = $this->validateGoodsReceiptRequest($request);
        $prepared = $this->prepareReceiptPayload($validated, null);
        $now = now();

        DB::transaction(function () use ($validated, $prepared, $now) {
            $goodsReceipt = GoodsReceiptC::create([
                'gRNumber' => $validated['gRNumber'],
                'supplierCode' => $validated['supplierCode'],
                'purchaseOrderId' => $prepared['purchaseOrder']->id,
                'purchaseOrderNumber' => $prepared['purchaseOrder']->pONumber,
                'supplierGuideNumber' => $validated['supplierGuideNumber'] ?? null,
                'gRDate' => $validated['gRDate'],
                'gRObservation' => $validated['gRObservation'] ?? null,
                'totalNet' => $prepared['totals']['totalNet'],
                'totalTax' => $prepared['totals']['totalTax'],
                'totalGross' => $prepared['totals']['totalGross'],
                'status' => 1,
                'created_by' => auth()->id(),
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            GoodsReceiptD::insert($prepared['detailRows']
                ->map(function ($row) use ($goodsReceipt) {
                    $row['goodsReceiptId'] = $goodsReceipt->id;
                    return $row;
                })
                ->values()
                ->all());

            $this->applyPurchaseOrderDeliveredQuantities($prepared['detailRows'], true);
        });

        return redirect()->route('goodsReceipt.all')->with([
            'message' => 'Entrada de mercadoria criada com sucesso.',
            'alert-type' => 'success',
        ]);
    }

    public function GoodsReceiptEdit($id)
    {
        $receipt = GoodsReceiptC::with(['detailLines', 'supplierLink', 'purchaseOrderLink.detailLines'])->findOrFail($id);

        if ((int) $receipt->status === 0) {
            return redirect()->route('goodsReceipt.all')->with([
                'message' => 'Não é possível editar uma entrada anulada.',
                'alert-type' => 'warning',
            ]);
        }

        $suppliers = $this->getSuppliers();
        $selectedSupplierCode = (int) $receipt->supplierCode;
        $selectedPurchaseOrder = $receipt->purchaseOrderLink;

        if (!$selectedPurchaseOrder) {
            throw ValidationException::withMessages([
                'purchaseOrderId' => 'A encomenda associada a esta entrada já não existe.',
            ]);
        }

        $receiptLineMap = $receipt->detailLines
            ->keyBy(function ($line) {
                return (int) $line->purchaseOrderDId;
            });

        $lineRows = $this->buildPurchaseOrderLinesForForm($selectedPurchaseOrder, $receiptLineMap);
        $totals = $this->buildTotalsFromLineRows($lineRows);
        $taxSummary = $this->buildTaxSummaryFromLineRows($lineRows);

        return view('backend.goodsReceipt.goodsReceipt_add', [
            'mode' => 'edit',
            'receipt' => $receipt,
            'suppliers' => $suppliers,
            'nextGRNumber' => (int) $receipt->gRNumber,
            'selectedSupplierCode' => $selectedSupplierCode,
            'selectedPurchaseOrder' => $selectedPurchaseOrder,
            'lineRows' => $lineRows,
            'taxSummary' => $taxSummary,
            'totals' => $totals,
        ]);
    }

    public function GoodsReceiptUpdate(Request $request)
    {
        $receipt = GoodsReceiptC::with('detailLines')->findOrFail($request->id);

        if ((int) $receipt->status === 0) {
            return redirect()->route('goodsReceipt.all')->with([
                'message' => 'Não é possível editar uma entrada anulada.',
                'alert-type' => 'warning',
            ]);
        }

        $validated = $this->validateGoodsReceiptRequest($request, $receipt->id, true);

        DB::transaction(function () use ($receipt, $validated) {
            $this->applyPurchaseOrderDeliveredQuantities($receipt->detailLines, false);
            GoodsReceiptD::where('goodsReceiptId', $receipt->id)->delete();

            $prepared = $this->prepareReceiptPayload($validated, $receipt);
            $now = now();

            $receipt->update([
                'gRNumber' => $validated['gRNumber'],
                'supplierCode' => $validated['supplierCode'],
                'purchaseOrderId' => $prepared['purchaseOrder']->id,
                'purchaseOrderNumber' => $prepared['purchaseOrder']->pONumber,
                'supplierGuideNumber' => $validated['supplierGuideNumber'] ?? null,
                'gRDate' => $validated['gRDate'],
                'gRObservation' => $validated['gRObservation'] ?? null,
                'totalNet' => $prepared['totals']['totalNet'],
                'totalTax' => $prepared['totals']['totalTax'],
                'totalGross' => $prepared['totals']['totalGross'],
                'updated_by' => auth()->id(),
                'updated_at' => $now,
            ]);

            GoodsReceiptD::insert($prepared['detailRows']
                ->map(function ($row) use ($receipt) {
                    $row['goodsReceiptId'] = $receipt->id;
                    return $row;
                })
                ->values()
                ->all());

            $this->applyPurchaseOrderDeliveredQuantities($prepared['detailRows'], true);
        });

        return redirect()->route('goodsReceipt.all')->with([
            'message' => 'Entrada de mercadoria atualizada com sucesso.',
            'alert-type' => 'success',
        ]);
    }

    public function GoodsReceiptSelectPurchaseOrder(Request $request)
    {
        $supplierCode = (int) $request->query('supplierCode', 0);
        $supplierGuideNumber = (string) $request->query('supplierGuideNumber', '');

        $purchaseOrders = PurchaseOrderC::with(['detailLines'])
            ->when($supplierCode > 0, function ($query) use ($supplierCode) {
                return $query->where('supplierCode', $supplierCode);
            })
            ->orderBy('pODate', 'DESC')
            ->orderBy('pONumber', 'DESC')
            ->get()
            ->map(function ($purchaseOrder) {
                $pendingLinesCount = $purchaseOrder->detailLines
                    ->filter(function ($line) {
                        return max((float) $line->quantity - (float) $line->deliveryQuantity, 0) > 0;
                    })
                    ->count();

                $purchaseOrder->pendingLinesCount = $pendingLinesCount;
                return $purchaseOrder;
            })
            ->filter(function ($purchaseOrder) {
                return (int) $purchaseOrder->pendingLinesCount > 0;
            })
            ->values();

        return view('backend.goodsReceipt.goodsReceipt_select_purchase_order', compact('supplierCode', 'supplierGuideNumber', 'purchaseOrders'));
    }

    public function GoodsReceiptAnnul($id)
    {
        $receipt = GoodsReceiptC::with('detailLines')->findOrFail($id);

        if ((int) $receipt->status === 0) {
            return redirect()->route('goodsReceipt.all')->with([
                'message' => 'A entrada já se encontra anulada.',
                'alert-type' => 'warning',
            ]);
        }

        DB::transaction(function () use ($receipt) {
            $this->applyPurchaseOrderDeliveredQuantities($receipt->detailLines, false);

            GoodsReceiptD::where('goodsReceiptId', $receipt->id)->update([
                'status' => 0,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

            $receipt->update([
                'status' => 0,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->route('goodsReceipt.all')->with([
            'message' => 'Entrada de mercadoria anulada com sucesso.',
            'alert-type' => 'success',
        ]);
    }

    public function GoodsReceiptPdf($id)
    {
        $receipt = GoodsReceiptC::with(['supplierLink', 'detailLines'])->findOrFail($id);

        $productDescriptions = Product::whereIn('code', $receipt->detailLines->pluck('productCode')->unique()->all())
            ->get(['code', 'description'])
            ->keyBy('code');

        $taxRates = TaxRate::all()->keyBy('taxRateCode');

        $detailLines = $receipt->detailLines
            ->sortBy('id')
            ->values()
            ->map(function ($line) use ($productDescriptions, $taxRates) {
                $taxRate = (float) optional($taxRates->get($line->taxRateCode))->taxRate;

                return [
                    'productCode' => $line->productCode,
                    'description' => optional($productDescriptions->get($line->productCode))->description,
                    'productUnit' => $line->productUnit,
                    'orderedQuantity' => (float) $line->orderedQuantity,
                    'previousDeliveredQuantity' => (float) $line->previousDeliveredQuantity,
                    'deliveryQuantity' => (float) $line->deliveryQuantity,
                    'pendingQuantity' => (float) $line->pendingQuantity,
                    'unitPrice' => (float) $line->unitPrice,
                    'taxRateCode' => $line->taxRateCode,
                    'taxRate' => $taxRate,
                    'lineNet' => round((float) $line->lineNet, 2),
                    'lineTax' => round((float) $line->lineTax, 2),
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

        $pdf = Pdf::loadView('backend.goodsReceipt.goodsReceipt_pdf', [
            'receipt' => $receipt,
            'detailLines' => $detailLines,
            'taxSummary' => $taxSummary,
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('entrada-mercadoria-' . $receipt->gRNumber . '.pdf');
    }

    private function getSuppliers()
    {
        return Supplier::orderBy('code')->get(['code', 'name']);
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

    private function getPurchaseOrderSelectionData(int $purchaseOrderId, int $supplierCode)
    {
        if ($purchaseOrderId <= 0) {
            return [
                'purchaseOrder' => null,
                'lineRows' => collect(),
                'taxSummary' => collect(),
                'totals' => [
                    'totalNet' => 0,
                    'totalTax' => 0,
                    'totalGross' => 0,
                ],
            ];
        }

        $purchaseOrder = PurchaseOrderC::with('detailLines')->findOrFail($purchaseOrderId);

        if ($supplierCode > 0 && (int) $purchaseOrder->supplierCode !== $supplierCode) {
            throw ValidationException::withMessages([
                'purchaseOrderId' => 'A encomenda selecionada não pertence ao fornecedor indicado.',
            ]);
        }

        $lineRows = $this->buildPurchaseOrderLinesForForm($purchaseOrder);
        $totals = $this->buildTotalsFromLineRows($lineRows);
        $taxSummary = $this->buildTaxSummaryFromLineRows($lineRows);

        return [
            'purchaseOrder' => $purchaseOrder,
            'lineRows' => $lineRows,
            'taxSummary' => $taxSummary,
            'totals' => $totals,
        ];
    }

    private function buildPurchaseOrderLinesForForm(PurchaseOrderC $purchaseOrder, $receiptLineMap = null)
    {
        $products = Product::whereIn('code', $purchaseOrder->detailLines->pluck('productCode')->unique()->all())
            ->get(['code', 'description'])
            ->keyBy('code');

        $taxRates = TaxRate::all()->keyBy('taxRateCode');
        $stockMap = $this->getStockSummaryByProduct($purchaseOrder->detailLines->pluck('productCode')->all());

        return $purchaseOrder->detailLines
            ->sortBy('id')
            ->values()
            ->map(function ($line) use ($products, $taxRates, $stockMap, $receiptLineMap) {
                $currentDeliveryInReceipt = 0;

                if ($receiptLineMap && $receiptLineMap->has((int) $line->id)) {
                    $currentDeliveryInReceipt = (float) $receiptLineMap->get((int) $line->id)->deliveryQuantity;
                }

                $ordered = (float) $line->quantity;
                $totalDelivered = (float) $line->deliveryQuantity;
                $alreadyDeliveredBeforeReceipt = max($totalDelivered - $currentDeliveryInReceipt, 0);
                $pending = max($ordered - $alreadyDeliveredBeforeReceipt, 0);
                $receive = min($pending, max($currentDeliveryInReceipt, 0));
                $stockData = $stockMap[(string) $line->productCode] ?? ['stockQuantity' => 0];
                $taxRate = (float) optional($taxRates->get($line->taxRateCode))->taxRate;

                return [
                    'purchaseOrderDId' => (int) $line->id,
                    'productCode' => (string) $line->productCode,
                    'productDescription' => (string) optional($products->get($line->productCode))->description,
                    'productUnit' => (string) ($line->productUnit ?? ''),
                    'stockQuantity' => (float) ($stockData['stockQuantity'] ?? 0),
                    'orderedQuantity' => round($ordered, 3),
                    'previousDeliveredQuantity' => round($alreadyDeliveredBeforeReceipt, 3),
                    'pendingQuantity' => round($pending, 3),
                    'receiveQuantity' => round($receive, 3),
                    'unitPrice' => round((float) $line->unitPrice, 2),
                    'taxRateCode' => (string) $line->taxRateCode,
                    'taxRate' => $taxRate,
                ];
            })
            ->filter(function ($lineRow) {
                return (float) $lineRow['pendingQuantity'] > 0;
            })
            ->values();
    }

    private function buildTotalsFromLineRows($lineRows)
    {
        $totalNet = 0;
        $totalTax = 0;

        foreach ($lineRows as $lineRow) {
            $lineNet = round((float) $lineRow['receiveQuantity'] * (float) $lineRow['unitPrice'], 2);
            $lineTax = round($lineNet * ((float) $lineRow['taxRate'] / 100), 2);

            $totalNet += $lineNet;
            $totalTax += $lineTax;
        }

        return [
            'totalNet' => round($totalNet, 2),
            'totalTax' => round($totalTax, 2),
            'totalGross' => round($totalNet + $totalTax, 2),
        ];
    }

    private function buildTaxSummaryFromLineRows($lineRows)
    {
        return collect($lineRows)
            ->groupBy('taxRateCode')
            ->map(function ($rows, $taxRateCode) {
                $first = $rows->first();

                $taxAmount = collect($rows)->sum(function ($row) {
                    $lineNet = round((float) $row['receiveQuantity'] * (float) $row['unitPrice'], 2);
                    return round($lineNet * ((float) $row['taxRate'] / 100), 2);
                });

                return [
                    'taxRateCode' => $taxRateCode,
                    'taxRate' => (float) ($first['taxRate'] ?? 0),
                    'taxAmount' => round($taxAmount, 2),
                ];
            })
            ->sortBy('taxRateCode')
            ->values();
    }

    private function validateGoodsReceiptRequest(Request $request, $ignoreId = null, $isUpdate = false)
    {
        $goodsReceiptTable = (new GoodsReceiptC())->getTable();
        $supplierTable = (new Supplier())->getTable();
        $purchaseOrderTable = (new PurchaseOrderC())->getTable();

        $grNumberRule = Rule::unique($goodsReceiptTable, 'gRNumber');

        if ($ignoreId) {
            $grNumberRule->ignore($ignoreId);
        }

        $rules = [
            'id' => ['nullable', 'integer', Rule::exists($goodsReceiptTable, 'id')],
            'gRNumber' => ['required', 'integer', 'min:1', $grNumberRule],
            'gRDate' => ['required', 'date'],
            'supplierCode' => ['required', Rule::exists($supplierTable, 'code')],
            'purchaseOrderId' => ['required', 'integer', Rule::exists($purchaseOrderTable, 'id')],
            'supplierGuideNumber' => ['nullable', 'string', 'max:50'],
            'gRObservation' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchaseOrderDId' => ['required', 'integer'],
            'lines.*.receiveQuantity' => ['required', 'numeric', 'min:0'],
        ];

        $messages = [
            'gRNumber.required' => 'Introduza o n.º da entrada.',
            'gRNumber.unique' => 'Já existe uma entrada com esse n.º.',
            'gRDate.required' => 'Introduza a data da entrada.',
            'supplierCode.required' => 'Selecione o fornecedor.',
            'purchaseOrderId.required' => 'Selecione uma encomenda a fornecedor.',
            'purchaseOrderId.exists' => 'A encomenda selecionada é inválida.',
            'lines.required' => 'A entrada deve ter linhas.',
            'lines.min' => 'A entrada deve ter pelo menos uma linha.',
            'lines.*.receiveQuantity.required' => 'Indique a quantidade a receber em todas as linhas.',
            'lines.*.receiveQuantity.numeric' => 'A quantidade a receber tem de ser numérica.',
            'lines.*.receiveQuantity.min' => 'A quantidade a receber não pode ser negativa.',
        ];

        if ($isUpdate) {
            $rules['id'] = ['required', 'integer', Rule::exists($goodsReceiptTable, 'id')];
        }

        return $request->validate($rules, $messages);
    }

    private function prepareReceiptPayload(array $validated, $currentReceipt = null)
    {
        $purchaseOrder = PurchaseOrderC::with('detailLines')->findOrFail($validated['purchaseOrderId']);

        if ((int) $purchaseOrder->supplierCode !== (int) $validated['supplierCode']) {
            throw ValidationException::withMessages([
                'purchaseOrderId' => 'A encomenda selecionada não pertence ao fornecedor indicado.',
            ]);
        }

        $poLinesById = $purchaseOrder->detailLines->keyBy(function ($line) {
            return (int) $line->id;
        });

        $taxMap = TaxRate::all()->keyBy('taxRateCode');

        $detailRows = collect();
        $totalNet = 0;
        $totalTax = 0;
        $now = now();

        foreach (collect($validated['lines'])->values() as $lineInput) {
            $poLineId = (int) ($lineInput['purchaseOrderDId'] ?? 0);
            $poLine = $poLinesById->get($poLineId);

            if (!$poLine) {
                throw ValidationException::withMessages([
                    'lines' => 'Existe uma linha inválida para a encomenda selecionada.',
                ]);
            }

            $ordered = (float) $poLine->quantity;
            $alreadyDelivered = (float) $poLine->deliveryQuantity;
            $receiveQuantity = round((float) ($lineInput['receiveQuantity'] ?? 0), 3);
            $pending = max($ordered - $alreadyDelivered, 0);

            if ($receiveQuantity <= 0) {
                continue;
            }

            if ($receiveQuantity > $pending) {
                throw ValidationException::withMessages([
                    'lines' => 'A quantidade a receber não pode ser superior à quantidade pendente.',
                ]);
            }

            $unitPrice = round((float) $poLine->unitPrice, 2);
            $lineNet = round($receiveQuantity * $unitPrice, 2);
            $taxRate = (float) optional($taxMap->get($poLine->taxRateCode))->taxRate;
            $lineTax = round($lineNet * ($taxRate / 100), 2);

            $totalNet += $lineNet;
            $totalTax += $lineTax;

            $detailRows->push([
                'purchaseOrderDId' => $poLineId,
                'productCode' => $poLine->productCode,
                'productUnit' => $poLine->productUnit,
                'taxRateCode' => $poLine->taxRateCode,
                'orderedQuantity' => round($ordered, 3),
                'previousDeliveredQuantity' => round($alreadyDelivered, 3),
                'deliveryQuantity' => $receiveQuantity,
                'pendingQuantity' => round(max($pending - $receiveQuantity, 0), 3),
                'unitPrice' => $unitPrice,
                'lineNet' => $lineNet,
                'lineTax' => $lineTax,
                'status' => 1,
                'created_by' => auth()->id(),
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($detailRows->isEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Indique pelo menos uma linha com quantidade a receber superior a zero.',
            ]);
        }

        return [
            'purchaseOrder' => $purchaseOrder,
            'detailRows' => $detailRows,
            'totals' => [
                'totalNet' => round($totalNet, 2),
                'totalTax' => round($totalTax, 2),
                'totalGross' => round($totalNet + $totalTax, 2),
            ],
        ];
    }

    private function applyPurchaseOrderDeliveredQuantities($detailRows, bool $increment)
    {
        foreach ($detailRows as $detailRow) {
            $poLineId = (int) (is_array($detailRow)
                ? ($detailRow['purchaseOrderDId'] ?? 0)
                : ($detailRow->purchaseOrderDId ?? 0));
            $quantity = (float) (is_array($detailRow)
                ? ($detailRow['deliveryQuantity'] ?? 0)
                : ($detailRow->deliveryQuantity ?? 0));

            if ($poLineId <= 0 || $quantity <= 0) {
                continue;
            }

            $poLine = PurchaseOrderD::find($poLineId);

            if (!$poLine) {
                continue;
            }

            $currentDelivered = (float) $poLine->deliveryQuantity;

            $newDelivered = $increment
                ? ($currentDelivered + $quantity)
                : max($currentDelivered - $quantity, 0);

            $poLine->update([
                'deliveryQuantity' => round($newDelivered, 3),
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
        }
    }
}
