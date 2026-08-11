<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('mirror_status')->nullable()->default('skipped')->after('user_id');
            $table->json('mirror_payload')->nullable()->after('mirror_status');
            $table->index(['business_id', 'mirror_status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'mirror_status']);
            $table->dropColumn(['mirror_status', 'mirror_payload']);
        });
    }
};
