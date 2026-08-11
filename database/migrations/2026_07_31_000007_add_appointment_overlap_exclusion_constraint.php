<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Solo en PostgreSQL: garantiza a nivel de BD que dos citas NO canceladas
     * nunca se solapen en el tiempo (protección real anti-doble-reserva bajo
     * concurrencia, que lockForUpdate no ofrece en SQLite).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (
                tsrange(start_time, end_time) WITH &&
            )
            WHERE (status <> 'cancelled')
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap');
    }
};
