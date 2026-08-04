<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('business_id')->default(1)->constrained('businesses')->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('business_id')->default(1)->constrained('businesses')->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->foreignId('business_id')->default(1)->constrained('businesses')->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('business_id')->default(1)->constrained('businesses')->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->index('business_id');
        });

        // Ensure all existing rows belong to business_id 1
        DB::table('contacts')->whereNull('business_id')->update(['business_id' => 1]);
        DB::table('appointments')->whereNull('business_id')->update(['business_id' => 1]);
        DB::table('inventory_items')->whereNull('business_id')->update(['business_id' => 1]);
        DB::table('messages')->whereNull('business_id')->update(['business_id' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn('business_id');
        });
    }
};
