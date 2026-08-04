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
        Schema::table('appointments', function (Blueprint $table) {
            // Guardar el ID del evento de Google Calendar para poder actualizar/cancelar luego.
            $table->string('google_event_id')->nullable()->after('status');

            // Índices para las consultas de disponibilidad y el guard anti-solapamiento.
            $table->index('start_time');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['start_time']);
            $table->dropIndex(['status']);
            $table->dropColumn('google_event_id');
        });
    }
};
