<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->nullableMorphs('payable'); // Order | Appointment
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('provider')->default('fake'); // payphone, stripe, presencial, fake
            $table->string('method')->default('online'); // online, presencial
            $table->integer('amount_cents')->default(0);
            $table->string('currency')->default('USD');
            $table->string('status')->default('pending'); // pending, paid, failed, cancelled
            $table->string('external_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('external_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
