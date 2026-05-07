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
        Schema::create('GoodsReceiptD', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('goodsReceiptId');
            $table->unsignedBigInteger('purchaseOrderDId');
            $table->string('productCode', 21);
            $table->string('productUnit', 25)->nullable();
            $table->integer('taxRateCode');
            $table->double('orderedQuantity')->default(0);
            $table->double('previousDeliveredQuantity')->default(0);
            $table->double('deliveryQuantity')->default(0);
            $table->double('pendingQuantity')->default(0);
            $table->double('unitPrice')->default(0);
            $table->double('lineNet')->default(0);
            $table->double('lineTax')->default(0);
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
        Schema::dropIfExists('GoodsReceiptD');
    }
};
