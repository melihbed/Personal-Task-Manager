<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A local copy of each Canvas assignment with the user's submission state. task_id links the planner task
     * made from it. task_created stays true after the user deletes that task (task_id becomes null), which is
     * how the sync knows not to make it again. task_synced_at is the task's updated_at after the sync last
     * wrote it; a different value later means the user changed the task.
     */
    public function up(): void
    {
        Schema::create('canvas_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('canvas_course_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('canvas_id');
            $table->string('name');
            $table->string('kind', 16)->default('assignment');
            $table->timestampTz('due_at')->nullable();
            $table->decimal('points_possible', 8, 2)->nullable();
            $table->string('html_url')->nullable();

            $table->boolean('submitted')->default(false);
            $table->boolean('missing')->default(false);
            $table->boolean('late')->default(false);
            $table->decimal('score', 8, 2)->nullable();

            $table->foreignId('task_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->boolean('task_created')->default(false);
            $table->timestampTz('task_synced_at')->nullable();

            $table->timestampsTz();

            $table->unique(['user_id', 'canvas_id']);
            $table->index(['user_id', 'due_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canvas_assignments');
    }
};
