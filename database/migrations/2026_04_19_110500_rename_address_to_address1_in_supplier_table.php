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
        Schema::table('Supplier', function (Blueprint $table) {
            if (Schema::hasColumn('Supplier', 'address') && !Schema::hasColumn('Supplier', 'address1')) {
                $table->renameColumn('address', 'address1');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Supplier', function (Blueprint $table) {
            if (Schema::hasColumn('Supplier', 'address1') && !Schema::hasColumn('Supplier', 'address')) {
                $table->renameColumn('address1', 'address');
            }
        });
    }
};
