<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap;');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_appointments;');

            DB::statement('
                ALTER TABLE appointments
                ADD CONSTRAINT appointments_no_overlap
                EXCLUDE USING gist (
                    business_id WITH =,
                    COALESCE(resource_id, COALESCE(doctor_id, 0)) WITH =,
                    tsrange(start_time, end_time, \'[)\') WITH &&
                ) WHERE (status != \'cancelled\');
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_no_overlap;');
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_appointments;');

            DB::statement('
                ALTER TABLE appointments
                ADD CONSTRAINT appointments_no_overlap
                EXCLUDE USING gist (
                    business_id WITH =,
                    COALESCE(doctor_id, 0) WITH =,
                    tsrange(start_time, end_time, \'[)\') WITH &&
                ) WHERE (status != \'cancelled\');
            ');
        }
    }
};
