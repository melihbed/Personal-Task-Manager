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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('responsibility_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('title');
            $table->text('notes')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'completed_at']);
            $table->index(['user_id', 'due_at']);
            $table->index('responsibility_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
