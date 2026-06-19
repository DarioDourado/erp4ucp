<?php

namespace App\Http\Controllers\Actl;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrderC;
use Illuminate\Support\Facades\Cache;

class ReportController extends Controller
{

    public function PendingReceiptsAll()
    {
        $orders = Cache::remember('pending_receipts_analytics', 300, function () {
            return PurchaseOrderC::with(['supplierLink', 'detailLines'])
                ->whereHas('detailLines', function ($q) {
                    $q->whereRaw('COALESCE(deliveryQuantity, 0) < quantity');
                })
                ->orderBy('pODate', 'DESC')
                ->orderBy('pONumber', 'DESC')
                ->get()
                ->map(fn ($order) => $this->decorateOrderTotals($order));
        })->filter(fn ($order) => $order->pendingValue > 0)->values();

        $kpis = [
            'totalOrders'    => $orders->count(),
            'totalOrdered'   => round($orders->sum('orderedValue'), 2),
            'totalReceived'  => round($orders->sum('receivedValue'), 2),
            'totalPending'   => round($orders->sum('pendingValue'), 2),
        ];

        $chartPendingBySupplier = $orders
            ->groupBy('supplierCode')
            ->map(function ($group) {
                return [
                    'supplier' => optional($group->first()->supplierLink)->name ?? $group->first()->supplierCode,
                    'value'    => round($group->sum('pendingValue'), 2),
                ];
            })
            ->sortByDesc('value')
            ->take(5)
            ->values();

        $chartPendingTrend = $orders
            ->groupBy(function ($order) {
                return $order->pODate ? date('Y-m', strtotime($order->pODate)) : 'Sem data';
            })
            ->map(function ($group, $month) {
                return [
                    'sortKey' => $month,
                    'month'   => $month === 'Sem data' ? $month : date('m/Y', strtotime($month . '-01')),
                    'pending' => round($group->sum('pendingValue'), 2),
                ];
            })
            ->sortBy('sortKey')
            ->map(fn ($i) => ['month' => $i['month'], 'pending' => $i['pending']])
            ->values();

        return view('backend.reports.pending_receipts_all', compact(
            'orders',
            'kpis',
            'chartPendingBySupplier',
            'chartPendingTrend'
        ));
    }

    public function PendingReceiptsDetail($id)
    {
        $order = PurchaseOrderC::with(['supplierLink', 'detailLines'])->findOrFail($id);
        $order = $this->decorateOrderTotals($order);

        $lines = $order->detailLines
            ->sortBy('id')
            ->values()
            ->map(function ($line) {
                $orderedQty  = (float) $line->quantity;
                $receivedQty = (float) $line->deliveryQuantity;
                $pendingQty  = max($orderedQty - $receivedQty, 0);
                $unitPrice   = (float) $line->unitPrice;

                return [
                    'productCode'    => $line->productCode,
                    'productFamily'  => $line->productFamily,
                    'productUnit'    => $line->productUnit,
                    'orderedQty'     => $orderedQty,
                    'receivedQty'    => $receivedQty,
                    'pendingQty'     => $pendingQty,
                    'unitPrice'      => $unitPrice,
                    'orderedValue'   => round($orderedQty * $unitPrice, 2),
                    'receivedValue'  => round($receivedQty * $unitPrice, 2),
                    'pendingValue'   => round($pendingQty * $unitPrice, 2),
                    'satisfactionPct'=> $orderedQty > 0
                        ? round(min($receivedQty / $orderedQty * 100, 100), 1)
                        : 100.0,
                    'isPending'      => $pendingQty > 0,
                ];
            });

        $kpis = [
            'totalLines'    => $lines->count(),
            'pendingLines'  => $lines->where('isPending', true)->count(),
            'totalOrdered'  => round($lines->sum('orderedValue'), 2),
            'totalReceived' => round($lines->sum('receivedValue'), 2),
            'totalPending'  => round($lines->sum('pendingValue'), 2),
        ];

        return view('backend.reports.pending_receipts_detail', compact('order', 'lines', 'kpis'));
    }


    private function decorateOrderTotals($order)
    {
        $orderedValue  = 0;
        $receivedValue = 0;

        foreach ($order->detailLines as $line) {
            $orderedQty  = (float) $line->quantity;
            $receivedQty = (float) $line->deliveryQuantity;
            $unitPrice   = (float) $line->unitPrice;

            $orderedValue  += round($orderedQty * $unitPrice, 2);
            $receivedValue += round(min($receivedQty, $orderedQty) * $unitPrice, 2);
        }

        $pendingValue    = max(round($orderedValue - $receivedValue, 2), 0);
        $satisfactionPct = $orderedValue > 0
            ? round(min($receivedValue / $orderedValue * 100, 100), 1)
            : 100.0;

        $order->orderedValue    = round($orderedValue, 2);
        $order->receivedValue   = round($receivedValue, 2);
        $order->pendingValue    = $pendingValue;
        $order->satisfactionPct = $satisfactionPct;

        return $order;
    }
}
