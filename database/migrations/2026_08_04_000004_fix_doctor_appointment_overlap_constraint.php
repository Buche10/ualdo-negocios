<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Permite citas paralelas en el mismo negocio siempre que sean con doctores distintos.
     * Si doctor_id es null, aplica COALESCE(doctor_id, 0) para agrupar citas sin doctor asignado.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap');

        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (
                business_id WITH =,
                COALESCE(doctor_id, 0) WITH =,
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

        DB::statement(<<<'SQL'
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_no_overlap
            EXCLUDE USING gist (
                business_id WITH =,
                tsrange(start_time, end_time) WITH &&
            )
            WHERE (status <> 'cancelled')
        SQL);
    }
};
