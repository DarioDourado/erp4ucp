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
        Schema::create('GoodsReceiptC', function (Blueprint $table) {
            $table->id();
            $table->integer('gRNumber')->unique();
            $table->integer('supplierCode');
            $table->unsignedBigInteger('purchaseOrderId')->nullable();
            $table->integer('purchaseOrderNumber')->nullable();
            $table->string('supplierGuideNumber', 50)->nullable();
            $table->date('gRDate')->nullable();
            $table->text('gRObservation')->nullable();
            $table->decimal('totalNet', 14, 2)->default(0);
            $table->decimal('totalTax', 14, 2)->default(0);
            $table->decimal('totalGross', 14, 2)->default(0);
            $table->tinyInteger('status')->default(1)->comment('0 = Anulado, 1 = Emitido');
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('GoodsReceiptC');
    }
};
