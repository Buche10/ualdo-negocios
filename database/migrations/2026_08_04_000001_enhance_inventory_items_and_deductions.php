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
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->integer('min_stock')->default(0)->after('stock');
        });

        Schema::create('service_supplies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('service_id')->constrained('inventory_items')->onDelete('cascade');
            $table->foreignId('supply_id')->constrained('inventory_items')->onDelete('cascade');
            $table->integer('quantity_required')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_supplies');
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('min_stock');
        });
    }
};
