<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Purchase Order OCR Defaults
    |--------------------------------------------------------------------------
    |
    | These defaults are used when the OCR process auto-creates entities
    | (suppliers, products) that don't exist in the database.
    |
    */

    'ocr' => [
        /*
         * Whether to auto-create suppliers when not found.
         * If false, the OCR will return an error when a supplier isn't found.
         */
        'auto_create_supplier' => env('PO_OCR_AUTO_CREATE_SUPPLIER', true),

        /*
         * Whether to auto-create products (articles) when not found.
         * If false, the OCR will return an error when a product isn't found.
         */
        'auto_create_product' => env('PO_OCR_AUTO_CREATE_PRODUCT', true),

        /*
         * Default family assigned to auto-created products.
         * Will be created automatically in the Family table if it doesn't exist.
         */
        'default_family' => env('PO_OCR_DEFAULT_FAMILY', 'GERAL'),

        /*
         * Default unit of measure assigned to auto-created products.
         * Will be created automatically in the UnitMeasure table if it doesn't exist.
         */
        'default_unit' => env('PO_OCR_DEFAULT_UNIT', 'UN'),

        /*
         * Default tax rate code (from TaxRate table) assigned to auto-created products
         * when the VAT percentage cannot be determined from OCR.
         */
        'default_tax_rate_code' => env('PO_OCR_DEFAULT_TAX_RATE_CODE', 23),

        /*
         * Prefix for auto-generated product codes.
         * The full code will be: {prefix}{next_id}
         * e.g., ART-1001
         */
        'product_code_prefix' => env('PO_OCR_PRODUCT_CODE_PREFIX', 'ART-'),
    ],
];
