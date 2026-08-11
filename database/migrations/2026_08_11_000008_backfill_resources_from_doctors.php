<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $doctors = DB::table('doctors')->get();

        foreach ($doctors as $doctor) {
            $resourceId = DB::table('resources')->insertGetId([
                'business_id' => $doctor->business_id,
                'name' => $doctor->name,
                'type' => 'doctor',
                'attributes' => json_encode(['specialty' => $doctor->specialty]),
                'capacity' => 1,
                'is_active' => $doctor->is_active ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('appointments')
                ->where('doctor_id', $doctor->id)
                ->update(['resource_id' => $resourceId]);
        }
    }

    public function down(): void
    {
        DB::table('appointments')->update(['resource_id' => null]);
        DB::table('resources')->where('type', 'doctor')->delete();
    }
};
