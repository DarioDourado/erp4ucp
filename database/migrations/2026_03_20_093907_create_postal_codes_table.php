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

        if (!Schema::hasTable('PostalCode')) {
            Schema::create('PostalCode', function (Blueprint $table) {
                $table->id();
                $table->string('postalCode')->unique();
                $table->string('location');
                $table->tinyInteger('status')->default('1');
                $table->integer('created_by')->nullable();
                $table->integer('updated_by')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('PostalCode');
    }
};
