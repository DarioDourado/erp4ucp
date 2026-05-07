<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Article', function (Blueprint $table) {
            $table->id();
            $table->string('code', 25)->unique();
            $table->string('description', 150);
            $table->string('image')->nullable();
            $table->foreignId('unitMeasure_id')->constrained('UnitMeasure')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('family_id')->constrained('Family')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('taxRate_id')->constrained('TaxRate')->cascadeOnUpdate()->restrictOnDelete();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Article');
    }
};
