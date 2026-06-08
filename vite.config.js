import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/goodsReceipt/goodsReceipt_all.css',
                'resources/js/goodsReceipt/goodsReceipt_all.js',
                'resources/css/goodsReceipt/goodsReceipt_add.css',
                'resources/js/goodsReceipt/goodsReceipt_add.js',
                'resources/js/goodsReceipt/goodsReceipt_select_purchase_order.js',
                'resources/css/purchaseOrder/purchaseOrderC_all.css',
                'resources/js/purchaseOrder/purchaseOrderC_all.js',
                'resources/css/purchaseOrder/purchaseOrderC_add.css',
                'resources/js/purchaseOrder/purchaseOrderC_add.js',
                'resources/css/purchaseOrder/purchaseOrderC_edit.css',
                'resources/js/purchaseOrder/purchaseOrderC_edit.js',
                'resources/css/purchaseOrder/purchaseOrder_analytics.css',
                'resources/js/purchaseOrder/purchaseOrder_analytics.js',
                'resources/css/reports/pending_receipts.css',
                'resources/js/reports/pending_receipts_all.js',
                'resources/js/reports/pending_receipts_detail.js',
            ],
            refresh: true,
        }),
    ],
});
