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
        Schema::create('staff_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->string('type')->default('note'); // note, reminder, task_log
            $table->timestamp('remind_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('crm_workspace_id')->nullable()->after('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_memories');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('crm_workspace_id');
        });
    }
};
