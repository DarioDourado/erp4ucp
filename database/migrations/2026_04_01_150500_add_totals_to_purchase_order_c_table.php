<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('PurchaseOrderC', function (Blueprint $table) {
            $table->decimal('financialDiscount', 14, 2)->default(0)->after('pOObservation');
            $table->decimal('totalNet', 14, 2)->default(0)->after('financialDiscount');
            $table->decimal('totalTax', 14, 2)->default(0)->after('totalNet');
            $table->decimal('totalGross', 14, 2)->default(0)->after('totalTax');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('PurchaseOrderC', function (Blueprint $table) {
            $table->dropColumn([
                'financialDiscount',
                'totalNet',
                'totalTax',
                'totalGross',
            ]);
        });
    }
};
