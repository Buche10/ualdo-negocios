<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill decimal prices to integer cents before changing column definition
        $items = DB::table('inventory_items')->get();
        foreach ($items as $item) {
            $cents = (int) round(((float) $item->price) * 100);
            DB::table('inventory_items')->where('id', $item->id)->update(['price' => $cents]);
        }

        // 2. Change price column definition to integer cents
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->integer('price')->default(0)->change();
        });
    }

    public function down(): void
    {
        // 1. Backfill integer cents to decimal prices before changing column definition
        $items = DB::table('inventory_items')->get();
        foreach ($items as $item) {
            $decimalPrice = round(((float) $item->price) / 100, 2);
            DB::table('inventory_items')->where('id', $item->id)->update(['price' => $decimalPrice]);
        }

        // 2. Change price column definition back to decimal
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->default(0)->change();
        });
    }
};
