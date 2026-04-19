<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('TaxRate', function (Blueprint $table) {
            $table->id();
            $table->integer('taxRateCode')->unique();
            $table->string('descriptionTaxRate', 25)->nullable();
            $table->double('taxRate');
            $table->tinyInteger('status')->default(1);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('TaxRate');
    }
};
