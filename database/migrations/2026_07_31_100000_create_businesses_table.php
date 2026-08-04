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
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('vertical')->default('health'); // health, aesthetic, restaurant, services
            $table->string('whatsapp_phone_number_id')->nullable()->index(); // Meta Cloud API phone_number_id
            $table->string('whatsapp_phone_number')->nullable();
            $table->string('timezone')->default('America/Guayaquil');
            $table->string('business_hours_start')->default('09:00');
            $table->string('business_hours_end')->default('18:00');
            $table->json('working_days')->nullable();
            $table->integer('slot_duration_minutes')->default(45);
            $table->string('currency')->default('USD');
            $table->string('telegram_chat_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Seed default business for backward compatibility with existing data
        DB::table('businesses')->insert([
            'id' => 1,
            'name' => 'Consultorio Salud Principal',
            'slug' => 'consultorio-salud-principal',
            'vertical' => 'health',
            'whatsapp_phone_number_id' => 'default_phone_id',
            'whatsapp_phone_number' => '593999999999',
            'timezone' => 'America/Guayaquil',
            'business_hours_start' => '09:00',
            'business_hours_end' => '18:00',
            'working_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']),
            'slot_duration_minutes' => 45,
            'currency' => 'USD',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
