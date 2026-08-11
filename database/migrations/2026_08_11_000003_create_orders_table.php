<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status')->default('draft'); // draft, confirmed, paid, fulfilled, cancelled
            $table->string('payment_method')->default('online'); // online, presencial, none
            $table->string('payment_status')->default('pending'); // pending, paid, failed
            $table->integer('subtotal_cents')->default(0);
            $table->integer('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->json('customer_info')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();

            $table->index('business_id');
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
