<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_mappings', function (Blueprint $table) {
            $table->string('external_uid')->nullable()->after('external_id');
            $table->index(['provider', 'external_uid']);
        });
    }

    public function down(): void
    {
        Schema::table('integration_mappings', function (Blueprint $table) {
            $table->dropIndex(['provider', 'external_uid']);
            $table->dropColumn('external_uid');
        });
    }
};
