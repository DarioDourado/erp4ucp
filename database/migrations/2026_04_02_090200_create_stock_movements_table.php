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
        Schema::create('StockMovement', function (Blueprint $table) {
            $table->id();
            $table->string('productCode', 21);
            $table->string('movementType', 10)->comment('IN / OUT');
            $table->string('sourceType', 30)->nullable()->comment('GOODS_RECEIPT / DELIVERY_NOTE');
            $table->unsignedBigInteger('sourceId')->nullable();
            $table->unsignedBigInteger('sourceLineId')->nullable();
            $table->date('movementDate')->nullable();
            $table->double('quantity')->default(0);
            $table->double('unitCost')->default(0);
            $table->double('totalCost')->default(0);
            $table->double('stockBalanceAfter')->default(0);
            $table->double('averageCostAfter')->default(0);
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
        Schema::dropIfExists('StockMovement');
    }
};
