<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('provider');
            $table->string('base_url');
            $table->text('api_key')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('config')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_integrations');
    }
};
