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
        Schema::create('PurchaseOrderD', function (Blueprint $table) {
            $table->id();
            $table->integer('pONumber');
            $table->date('pODateDelivery')->nullable();
            $table->string('productCode', 21);
            $table->string('productFamily', 25)->nullable();
            $table->string('productUnit', 25)->nullable();
            $table->integer('taxRateCode');
            $table->double('quantity')->nullable();
            $table->double('deliveryQuantity')->nullable();
            $table->double('dicountPercent')->nullable();
            $table->double('unitPrice')->nullable();
            $table->double('sellingPrice')->nullable();
            $table->tinyInteger('status')->default('0')->comment('0 = Pendente, 1 = Aprovado');
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
        Schema::dropIfExists('PurchaseOrderD');
    }
};
