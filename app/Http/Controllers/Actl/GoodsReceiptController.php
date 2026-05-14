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
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use thiagoalessio\TesseractOCR\TesseractOCR;

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

    // ===== MÉTODOS OCR PARA ENTRADA DE MERCADORIA =====

    public function showOCR()
    {
        return view('backend.goodsReceipt.goodsReceipt_ocr');
    }

    // Método de teste para diagnóstico
    public function testOCR()
    {
        $diagnostics = [
            'php_os_family' => PHP_OS_FAMILY,
            'tesseract_installed' => false,
            'tesseract_path' => null,
            'tesseract_version' => null,
            'storage_writable' => false,
            'temp_dir_exists' => false,
            'errors' => []
        ];

        try {
            // Testar se Tesseract está instalado (verifica exit code)
            $testCommand = PHP_OS_FAMILY === 'Windows' 
                ? 'where tesseract 2>&1' 
                : 'which tesseract 2>&1';
            
            exec($testCommand, $output, $exitCode);
            $testOutput = implode("\n", $output);
            
            if ($exitCode === 0 && !empty($output) && strpos($testOutput, 'Could not find') === false) {
                $diagnostics['tesseract_installed'] = true;
                $diagnostics['tesseract_path'] = trim($output[0] ?? $testOutput);
                
                // Tentar obter versão
                exec('tesseract --version 2>&1', $versionOutput, $versionCode);
                if ($versionCode === 0 && !empty($versionOutput)) {
                    $diagnostics['tesseract_version'] = $versionOutput[0];
                }
            } else {
                $diagnostics['errors'][] = 'Tesseract não encontrado no PATH. Output: ' . $testOutput;
            }

            // Testar storage
            $storagePath = storage_path('app/public/temp_documents');
            if (!is_dir($storagePath)) {
                @mkdir($storagePath, 0755, true);
            }
            $diagnostics['storage_writable'] = is_writable(storage_path('app/public'));
            $diagnostics['temp_dir_exists'] = is_dir($storagePath);

        } catch (\Exception $e) {
            $diagnostics['errors'][] = $e->getMessage();
        }

        return response()->json($diagnostics);
    }

    public function uploadDocument(Request $request)
    {
        try {
            $requestId = uniqid('ocr_', true);

            Log::info('OCR upload iniciado', [
                'requestId' => $requestId,
                'ip' => $request->ip(),
                'userId' => auth()->id(),
            ]);

            // Validação com feedback melhorado
            $validated = $request->validate([
                'document' => 'required|file|mimes:pdf,jpeg,jpg,png|max:20480'
            ]);

            $file = $request->file('document');

            Log::info('OCR upload validado', [
                'requestId' => $requestId,
                'originalName' => $file?->getClientOriginalName(),
                'mimeType' => $file?->getMimeType(),
                'sizeBytes' => $file?->getSize(),
            ]);
            
            // Verificar se o ficheiro foi realmente feito upload
            if (!$file || !$file->isValid()) {
                Log::warning('OCR upload inválido', [
                    'requestId' => $requestId,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Erro ao fazer upload do ficheiro. Tente novamente.'
                ], 400);
            }

            $path = $file->store('temp_documents', 'public');
            $fullPath = storage_path('app/public/' . $path);

            // Verificar se o ficheiro existe
            if (!file_exists($fullPath)) {
                Log::error('Ficheiro OCR não encontrado após store', [
                    'requestId' => $requestId,
                    'storedPath' => $path,
                    'fullPath' => $fullPath,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Erro ao processar ficheiro. Ficheiro não foi armazenado.'
                ], 500);
            }

            Log::info('Ficheiro OCR armazenado', [
                'requestId' => $requestId,
                'storedPath' => $path,
                'fullPath' => $fullPath,
            ]);

            // Extrair texto com OCR
            $extractedText = $this->extractTextFromDocument($fullPath, $requestId);

            Log::info('OCR extração concluída', [
                'requestId' => $requestId,
                'chars' => mb_strlen($extractedText),
                'preview' => $this->buildTextPreview($extractedText),
            ]);

            if (empty($extractedText)) {
                Log::warning('OCR sem texto extraído', [
                    'requestId' => $requestId,
                    'storedPath' => $path,
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Não foi possível extrair texto do documento. O documento pode estar muito escuro ou em formato inválido.'
                ], 400);
            }

            // Parse dos dados
            $parsedData = $this->parseDocumentData($extractedText, $requestId);

            if (!$parsedData['poNumber']) {
                Log::warning('Parsing OCR sem número de encomenda', [
                    'requestId' => $requestId,
                    'linesExtracted' => count($parsedData['lines'] ?? []),
                    'preview' => $this->buildTextPreview($extractedText),
                ]);

                return response()->json([
                    'success' => false,
                    'error' => 'Não foi possível encontrar o número da encomenda no documento. Certifique-se de que o documento está legível.'
                ], 400);
            }

            // Buscar encomenda e artigos na BD
            $enrichedData = $this->enrichDataWithDatabase($parsedData, $requestId);

            if (isset($enrichedData['error'])) {
                Log::warning('OCR enriquecimento sem correspondência de encomenda', [
                    'requestId' => $requestId,
                    'poNumberParsed' => $parsedData['poNumber'] ?? null,
                    'error' => $enrichedData['error'],
                ]);

                return response()->json([
                    'success' => false,
                    'error' => $enrichedData['error']
                ], 404);
            }

            Log::info('OCR processado com sucesso', [
                'requestId' => $requestId,
                'purchaseOrderId' => $enrichedData['purchaseOrderId'] ?? null,
                'poNumber' => $enrichedData['poNumber'] ?? null,
                'lineCount' => count($enrichedData['lines'] ?? []),
                'totalValue' => $enrichedData['totalValue'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => $enrichedData,
                'document_path' => $path
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('OCR erro de validação', [
                'errors' => $e->errors(),
                'userId' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erro de validação: ' . implode(', ', array_map(function($errors) {
                    return is_array($errors) ? implode(', ', $errors) : $errors;
                }, $e->errors()))
            ], 422);
        } catch (\Exception $e) {
            Log::error('Erro no OCR upload', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'userId' => auth()->id(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Erro ao processar documento: ' . $e->getMessage()
            ], 500);
        }
    }

    private function extractTextFromDocument($path, ?string $requestId = null)
    {
        try {
            Log::info('OCR extração iniciada', [
                'requestId' => $requestId,
                'path' => $path,
                'osFamily' => PHP_OS_FAMILY,
            ]);

            $extension = strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));

            if ($extension === 'pdf') {
                Log::info('OCR documento PDF detetado', [
                    'requestId' => $requestId,
                    'path' => $path,
                ]);

                return $this->extractTextFromPdf($path, $requestId);
            }

            // Verificar se o Tesseract está instalado
            $testCommand = PHP_OS_FAMILY === 'Windows' 
                ? 'where tesseract 2>&1' 
                : 'which tesseract 2>&1';
            
            exec($testCommand, $output, $exitCode);
            $testOutput = implode("\n", $output);

            Log::info('OCR verificação tesseract executada', [
                'requestId' => $requestId,
                'exitCode' => $exitCode,
                'output' => $testOutput,
            ]);
            
            // Verificar se foi encontrado (exit code 0 = sucesso)
            if ($exitCode !== 0 || empty($output) || strpos($testOutput, 'not found') !== false || strpos($testOutput, 'not recognized') !== false || strpos($testOutput, 'Could not find') !== false) {
                throw new \Exception('Tesseract OCR não está instalado no servidor. Instale usando: choco install tesseract ou https://github.com/UB-Mannheim/tesseract/wiki');
            }

            $text = $this->runTesseractOnImage($path, $requestId);

            Log::info('OCR engine concluída', [
                'requestId' => $requestId,
                'chars' => mb_strlen($text),
            ]);
            
            return trim($text);
        } catch (\Exception $e) {
            Log::error('Erro na extração OCR', [
                'requestId' => $requestId,
                'path' => $path,
                'message' => $e->getMessage(),
            ]);
            throw new \Exception('Erro ao executar OCR: ' . $e->getMessage());
        }
    }

    private function extractTextFromPdf(string $pdfPath, ?string $requestId = null): string
    {
        if (!$this->isCommandAvailable('pdftoppm')) {
            Log::error('OCR PDF sem conversor disponível', [
                'requestId' => $requestId,
                'pdfPath' => $pdfPath,
                'requiredCommand' => 'pdftoppm',
            ]);

            throw new \Exception('OCR de PDF requer o utilitário pdftoppm (Poppler). Instale e adicione ao PATH para processar PDFs completos.');
        }

        $tempDir = storage_path('app/public/temp_documents');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $baseName = 'ocr_pdf_' . str_replace('.', '_', uniqid('', true));
        $outputBase = $tempDir . DIRECTORY_SEPARATOR . $baseName;
        $command = $this->buildPdftoppmCommand($pdfPath, $outputBase);

        Log::info('OCR PDF conversão iniciada', [
            'requestId' => $requestId,
            'pdfPath' => $pdfPath,
            'outputBase' => $outputBase,
            'command' => $command,
        ]);

        exec($command, $conversionOutput, $conversionExitCode);

        Log::info('OCR PDF conversão concluída', [
            'requestId' => $requestId,
            'exitCode' => $conversionExitCode,
            'output' => implode("\n", $conversionOutput),
        ]);

        if ($conversionExitCode !== 0) {
            throw new \Exception('Falha na conversão do PDF para imagem antes do OCR.');
        }

        $generatedImages = glob($outputBase . '-*.png') ?: [];
        natsort($generatedImages);
        $generatedImages = array_values($generatedImages);

        if (empty($generatedImages)) {
            throw new \Exception('Nenhuma página foi gerada a partir do PDF para OCR.');
        }

        Log::info('OCR PDF páginas geradas', [
            'requestId' => $requestId,
            'pageCount' => count($generatedImages),
            'pages' => $generatedImages,
        ]);

        $fullText = '';
        foreach ($generatedImages as $index => $imagePath) {
            $pageNumber = $index + 1;

            $pageText = $this->runTesseractOnImage($imagePath, $requestId, [
                'page' => $pageNumber,
            ]);

            Log::info('OCR PDF página processada', [
                'requestId' => $requestId,
                'page' => $pageNumber,
                'imagePath' => $imagePath,
                'chars' => mb_strlen($pageText),
            ]);

            $fullText .= trim($pageText) . "\n\n";
        }

        // Limpeza dos ficheiros temporários gerados na conversão.
        foreach ($generatedImages as $imagePath) {
            if (is_file($imagePath)) {
                @unlink($imagePath);
            }
        }

        return trim($fullText);
    }

    private function runTesseractOnImage(string $path, ?string $requestId = null, array $extraContext = []): string
    {
        Log::info('OCR engine iniciada para imagem', array_merge([
            'requestId' => $requestId,
            'path' => $path,
        ], $extraContext));

        $ocr = new TesseractOCR($path);
        $ocr->lang('por');
        $ocr->timeout(60);
        $ocr->psm(6);

        $text = $ocr->run();

        Log::info('OCR engine concluída para imagem', array_merge([
            'requestId' => $requestId,
            'path' => $path,
            'chars' => mb_strlen((string) $text),
        ], $extraContext));

        return trim((string) $text);
    }

    private function isCommandAvailable(string $command): bool
    {
        $checkCommand = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($command) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($command) . ' 2>/dev/null';

        exec($checkCommand, $output, $exitCode);

        return $exitCode === 0;
    }

    private function buildPdftoppmCommand(string $pdfPath, string $outputBase): string
    {
        return 'pdftoppm -r 300 -png '
            . escapeshellarg($pdfPath)
            . ' '
            . escapeshellarg($outputBase)
            . ' 2>&1';
    }

    private function parseDocumentData($text, ?string $requestId = null)
    {
        $poNumber = null;
        $supplierGuideNumber = null;
        $lines = [];
        $poPatternUsed = null;
        $guidePatternUsed = null;

        Log::info('OCR parsing iniciado', [
            'requestId' => $requestId,
            'chars' => mb_strlen((string) $text),
            'preview' => $this->buildTextPreview((string) $text),
        ]);

        // A encomenda é extraída a partir do padrão explícito "ENCOMENDA:"
        if (preg_match('/\bENCOMENDA\s*:\s*([A-Z0-9\-\/]+)/i', (string) $text, $matches)) {
            $poNumber = trim((string) ($matches[1] ?? ''));
            $poPatternUsed = 'encomenda-colon';
        } elseif (preg_match('/\bENCOMENDA\s+([A-Z0-9\-\/]+)/i', (string) $text, $matches)) {
            $poNumber = trim((string) ($matches[1] ?? ''));
            $poPatternUsed = 'encomenda-fallback';
        }

        // A guia é extraída a partir do padrão explícito "GUIA:" (inclui variantes com Nº).
        if (preg_match('/\b(?:N[ºO]\.??\s*)?GUIA\s*:\s*([A-Z0-9][A-Z0-9\-\/.]*)/iu', (string) $text, $matches)) {
            $supplierGuideNumber = trim((string) ($matches[1] ?? ''));
            $guidePatternUsed = 'guia-colon';
        } elseif (preg_match('/\bGUIA\s+N[ºO]\.??\s*[:\-]?\s*([A-Z0-9][A-Z0-9\-\/.]*)/iu', (string) $text, $matches)) {
            $supplierGuideNumber = trim((string) ($matches[1] ?? ''));
            $guidePatternUsed = 'guia-n-fallback';
        }

        $tabularLines = $this->extractTabularLinesFromOcrText((string) $text);

        if (!empty($tabularLines)) {
            $lines = $tabularLines;
        }

        // Extrair linhas onde Artigo representa o descritivo e Qtd representa a quantidade.
        if (empty($lines)) {
            $rows = preg_split('/\R/u', (string) $text) ?: [];
            foreach ($rows as $row) {
                $cleanRow = trim((string) preg_replace('/\s+/', ' ', (string) $row));

                if ($cleanRow === '' || preg_match('/\b(artigo|qtd|quantidade|pre[cç]o|total|iva|entregue|receber|pedida)\b/i', $cleanRow)) {
                    continue;
                }

                // Ex.: "ROLHA NATURAL Qtd 120" ou "ROLHA NATURAL QTD: 120"
                if (preg_match('/^(.+?)\s+QTD\.?\s*[:\-]?\s*(\d+(?:[.,]\d+)?)/i', $cleanRow, $lineMatches)) {
                    $lines[] = [
                        'articleDescription' => trim((string) ($lineMatches[1] ?? '')),
                        'quantity' => $this->normalizeOcrNumber((string) ($lineMatches[2] ?? '0')),
                        'source' => 'qtd-labeled',
                    ];
                    continue;
                }

                // Aceita separador visual apenas com pipe para não confundir vírgulas decimais com quantidade.
                if (preg_match('/^(.+?)\s*\|\s*(\d+(?:[.,]\d+)?)$/', $cleanRow, $lineMatches)) {
                    $lines[] = [
                        'articleDescription' => trim((string) ($lineMatches[1] ?? '')),
                        'quantity' => $this->normalizeOcrNumber((string) ($lineMatches[2] ?? '0')),
                        'source' => 'pipe-separator',
                    ];
                    continue;
                }

                // Fallback para linhas simples com quantidade no fim.
                if (preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)$/', $cleanRow, $lineMatches)) {
                    $candidateDescription = trim((string) ($lineMatches[1] ?? ''));
                    $candidateQuantity = $this->normalizeOcrNumber((string) ($lineMatches[2] ?? '0'));

                    // Evita ruído típico (números de documento, datas, totais e impostos).
                    if ($candidateQuantity > 0 && !preg_match('/\b(encomenda|data|total|iva|valor|guia|cliente)\b/i', $candidateDescription)) {
                        $lines[] = [
                            'articleDescription' => $candidateDescription,
                            'quantity' => $candidateQuantity,
                            'source' => 'trailing-qty',
                        ];
                    }
                }
            }
        }

        $lines = collect($lines)
            ->filter(function ($line) {
                return !empty($line['articleDescription']) && (float) ($line['quantity'] ?? 0) > 0;
            })
            ->values()
            ->all();

        Log::info('OCR parsing concluído', [
            'requestId' => $requestId,
            'poNumber' => $poNumber,
            'poPatternUsed' => $poPatternUsed,
            'supplierGuideNumber' => $supplierGuideNumber,
            'guidePatternUsed' => $guidePatternUsed,
            'lineCount' => count($lines),
            'lineArticles' => collect($lines)->pluck('articleDescription')->filter()->values()->all(),
        ]);

        return [
            'poNumber' => $poNumber,
            'supplierGuideNumber' => $supplierGuideNumber,
            'lines' => $lines
        ];
    }

    private function enrichDataWithDatabase($parsedData, ?string $requestId = null)
    {
        Log::info('OCR enriquecimento iniciado', [
            'requestId' => $requestId,
            'poNumberParsed' => $parsedData['poNumber'] ?? null,
            'parsedLineCount' => count($parsedData['lines'] ?? []),
            'parsedArticles' => collect($parsedData['lines'] ?? [])->pluck('articleDescription')->filter()->values()->all(),
        ]);

        // Encontrar Encomenda no sistema
        $purchaseOrder = PurchaseOrderC::where('pONumber', $parsedData['poNumber'])
            ->with(['detailLines', 'supplierLink'])
            ->first();

        if (!$purchaseOrder) {
            // Tentar variações
            $purchaseOrder = PurchaseOrderC::where('pONumber', 'like', '%' . substr($parsedData['poNumber'], -5))
                ->with(['detailLines', 'supplierLink'])
                ->first();

            Log::info('OCR tentativa fallback encomenda', [
                'requestId' => $requestId,
                'poNumberParsed' => $parsedData['poNumber'] ?? null,
                'suffix' => substr((string) ($parsedData['poNumber'] ?? ''), -5),
                'found' => (bool) $purchaseOrder,
            ]);
        }

        if (!$purchaseOrder) {
            return ['error' => 'Encomenda "' . $parsedData['poNumber'] . '" não encontrada no sistema.'];
        }

        $poLines = $purchaseOrder->detailLines->values();
        $productDescriptionsByCode = Product::whereIn('code', $poLines->pluck('productCode')->filter()->unique()->values()->all())
            ->get(['code', 'description'])
            ->pluck('description', 'code');
        $receivedByPoLine = [];

        foreach (collect($parsedData['lines'] ?? []) as $parsedLine) {
            $articleDescription = trim((string) ($parsedLine['articleDescription'] ?? ''));
            $quantity = (float) ($parsedLine['quantity'] ?? 0);

            if ($articleDescription === '' || $quantity <= 0) {
                continue;
            }

            $bestMatch = $this->findBestMatchingPurchaseOrderLine(
                $parsedLine,
                $poLines,
                $productDescriptionsByCode
            );
            xdebug_break();

            Log::info('OCR matching fuzzy por descritivo', [
                'requestId' => $requestId,
                'articleDescription' => $articleDescription,
                'quantity' => $quantity,
                'parsedUnitPrice' => (float) ($parsedLine['unitPrice'] ?? 0),
                'bestScore' => $bestMatch['score'],
                'purchaseOrderDId' => optional($bestMatch['line'])->id,
                'matchedDescription' => $bestMatch['lineDescription'],
                'matchedUnitPrice' => $bestMatch['lineUnitPrice'],
            ]);

            if (!$bestMatch['line'] || $bestMatch['score'] < 33) {
                continue;
            }

            $lineId = (int) $bestMatch['line']->id;
            $receivedByPoLine[$lineId] = ($receivedByPoLine[$lineId] ?? 0) + $quantity;
        }

        // Matching final com linhas da BD e quantidades agregadas do OCR.
        $enrichedLines = [];
        foreach ($poLines as $dbLine) {
            $productCode = (string) ($dbLine->productCode ?? '');
            $productName = (string) ($productDescriptionsByCode->get($productCode) ?? $productCode);

            $lineId = (int) $dbLine->id;
            $quantityReceived = round((float) ($receivedByPoLine[$lineId] ?? 0), 3);

            Log::info('OCR matching final de linha', [
                'requestId' => $requestId,
                'purchaseOrderDId' => $lineId,
                'dbProductCode' => $productCode,
                'dbDescription' => $productName,
                'quantityOrdered' => (float) $dbLine->quantity,
                'quantityReceived' => $quantityReceived,
            ]);

            $enrichedLines[] = [
                'purchaseOrderDId' => $lineId,
                'productCode' => $productCode,
                'productName' => $productName,
                'quantityOrdered' => (float) $dbLine->quantity,
                'quantityReceived' => $quantityReceived,
                'unitPrice' => (float) $dbLine->unitPrice,
                'totalPrice' => $quantityReceived * (float) $dbLine->unitPrice,
                'taxRateCode' => $dbLine->taxRateCode,
            ];
        }

        $totalValue = collect($enrichedLines)->sum('totalPrice');

        Log::info('OCR enriquecimento concluído', [
            'requestId' => $requestId,
            'purchaseOrderId' => $purchaseOrder->id,
            'poNumber' => $purchaseOrder->pONumber,
            'dbLineCount' => $purchaseOrder->detailLines->count(),
            'enrichedLineCount' => count($enrichedLines),
            'totalValue' => round($totalValue, 2),
        ]);

        return [
            'poNumber' => $purchaseOrder->pONumber,
            'supplier' => $purchaseOrder->supplierLink->name,
            'supplierCode' => $purchaseOrder->supplierCode,
            'supplierGuideNumber' => trim((string) ($parsedData['supplierGuideNumber'] ?? '')),
            'purchaseOrderId' => $purchaseOrder->id,
            'lines' => $enrichedLines,
            'totalValue' => round($totalValue, 2),
            'nextGRNumber' => ((int) GoodsReceiptC::max('gRNumber')) + 1
        ];
    }

    private function buildTextPreview(string $text, int $limit = 400): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? '';

        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $limit) . '...';
    }

    private function extractTabularLinesFromOcrText(string $text): array
    {
        $normalizedText = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        if ($normalizedText === '') {
            return [];
        }

        $patterns = [
            // Layout completo: Ln Artigo Unid. Pedida Entregue Receber Preço IVA Total
            '/\b(?P<line>\d+)\s+(?P<description>.+?)\s+(?P<unit>[A-Z]{1,6})\s+(?P<ordered>\d+(?:[.,]\d+)?)\s+(?P<delivered>\d+(?:[.,]\d+)?)\s+(?P<quantity>\d+(?:[.,]\d+)?)\s+(?P<unitPrice>\d+(?:[.,]\d+)?)\s+\d+(?:\s*\(\d+(?:[.,]\d+)?%\))?\s+(?P<lineTotal>\d+(?:[.,]\d+)?)(?=\s+\d+\s+.+?\s+[A-Z]{1,6}\s+\d+(?:[.,]\d+)?\s+\d+(?:[.,]\d+)?\s+\d+(?:[.,]\d+)?\s+\d+(?:[.,]\d+)?\s+\d+|\s+Total\b|$)/u',
            // Layout simples: Ln Artigo Unid. Qtd. Preço IVA Total
            '/\b(?P<line>\d+)\s+(?P<description>.+?)\s+(?P<unit>[A-Z]{1,6})\s+(?P<quantity>\d+(?:[.,]\d+)?)\s+(?P<unitPrice>\d+(?:[.,]\d+)?)\s+\d+(?:\s*\(\d+(?:[.,]\d+)?%\))?\s+(?P<lineTotal>\d+(?:[.,]\d+)?)(?=\s+\d+\s+.+?\s+[A-Z]{1,6}\s+\d+(?:[.,]\d+)?\s+\d+(?:[.,]\d+)?\s+\d+|\s+Total\b|$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $normalizedText, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $lines = collect($matches)
                ->map(function ($match) {
                    return [
                        'articleDescription' => $this->sanitizeOcrArticleDescription((string) ($match['description'] ?? '')),
                        'quantity' => $this->normalizeOcrNumber((string) ($match['quantity'] ?? '0')),
                        'unitPrice' => $this->normalizeOcrNumber((string) ($match['unitPrice'] ?? '0')),
                        'lineTotal' => $this->normalizeOcrNumber((string) ($match['lineTotal'] ?? '0')),
                        'source' => 'tabular',
                    ];
                })
                ->filter(function ($line) {
                    return !empty($line['articleDescription']) && (float) ($line['quantity'] ?? 0) > 0;
                })
                ->values()
                ->all();

            if (!empty($lines)) {
                return $lines;
            }
        }

        return [];
    }

    private function sanitizeOcrArticleDescription(string $value): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $clean = preg_replace('/^\d+\s+/', '', $clean) ?? $clean;

        return trim($clean);
    }

    private function normalizeOcrNumber(string $value): float
    {
        $normalized = trim($value);
        $normalized = str_replace([' ', "\u{00A0}"], '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return round((float) $normalized, 3);
    }

    private function findBestMatchingPurchaseOrderLine(array $parsedLine, $poLines, $productDescriptionsByCode): array
    {
        $articleDescription = trim((string) ($parsedLine['articleDescription'] ?? ''));
        $parsedUnitPrice = (float) ($parsedLine['unitPrice'] ?? 0);
        $bestLine = null;
        $bestScore = 0;
        $bestLineDescription = '';
        $bestLineUnitPrice = 0;

        foreach ($poLines as $poLine) {
            $productCode = (string) ($poLine->productCode ?? '');
            $candidateDescription = (string) ($productDescriptionsByCode->get($productCode) ?? $productCode);

            $descriptionScore = $this->calculateDescriptionSimilarity($articleDescription, $candidateDescription);
            $priceScore = 0;

            if ($parsedUnitPrice > 0 && (float) $poLine->unitPrice > 0) {
                $priceDiff = abs($parsedUnitPrice - (float) $poLine->unitPrice);
                $priceBase = max($parsedUnitPrice, (float) $poLine->unitPrice, 0.01);
                $priceScore = max(0, 100 - (($priceDiff / $priceBase) * 100));
            }

            $score = ($descriptionScore * 0.75) + ($priceScore * 0.25);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLine = $poLine;
                $bestLineDescription = $candidateDescription;
                $bestLineUnitPrice = (float) $poLine->unitPrice;
            }
        }

        return [
            'line' => $bestLine,
            'score' => round($bestScore, 2),
            'lineDescription' => $bestLineDescription,
            'lineUnitPrice' => $bestLineUnitPrice,
        ];
    }

    private function calculateDescriptionSimilarity(string $left, string $right): float
    {
        $normalizedLeft = $this->normalizeTextForFuzzyMatch($left);
        $normalizedRight = $this->normalizeTextForFuzzyMatch($right);

        if ($normalizedLeft === '' || $normalizedRight === '') {
            return 0.0;
        }

        similar_text($normalizedLeft, $normalizedRight, $similarityPercent);

        $leftTokens = collect(explode(' ', $normalizedLeft))
            ->filter(function ($token) {
                return mb_strlen((string) $token) > 2;
            })
            ->unique()
            ->values();
        $rightTokens = collect(explode(' ', $normalizedRight))
            ->filter(function ($token) {
                return mb_strlen((string) $token) > 2;
            })
            ->unique()
            ->values();

        $intersectionCount = $leftTokens->intersect($rightTokens)->count();
        $tokenBase = max($leftTokens->count(), 1);
        $tokenScore = ($intersectionCount / $tokenBase) * 100;

        return round(($similarityPercent * 0.7) + ($tokenScore * 0.3), 2);
    }

    private function normalizeTextForFuzzyMatch(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        if ($transliterated !== false) {
            $normalized = strtolower($transliterated);
        }

        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? '';

        return $normalized;
    }
}
