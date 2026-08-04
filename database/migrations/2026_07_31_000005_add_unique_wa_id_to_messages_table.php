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
        Schema::table('messages', function (Blueprint $table) {
            // Idempotencia a nivel de BD: un mismo wa_id de WhatsApp no puede insertarse dos veces.
            // (Los NULL siguen permitidos para mensajes salientes/web sin wa_id.)
            $table->unique('wa_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['wa_id']);
        });
    }
};
