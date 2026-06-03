<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$products = \App\Models\Product::whereRaw('LENGTH(description) < 3')->get(['code', 'description']);
echo "Products with short description:\n";
foreach ($products as $p) {
    echo "  code={$p->code} desc='{$p->description}'\n";
}

// Just check - don't delete
echo "\nProducts with only punctuation (no letters):\n";
$punct = \App\Models\Product::whereRaw("description NOT REGEXP '[A-Za-zÀ-ÿ]'")->get(['code', 'description']);
foreach ($punct as $p) {
    echo "  code={$p->code} desc='{$p->description}'\n";
}
echo "Total: " . $punct->count() . "\n";
