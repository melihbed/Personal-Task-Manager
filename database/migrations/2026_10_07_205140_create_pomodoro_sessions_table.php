<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One focus round or break. The timer lives here, not in the browser, so it survives refreshes: remaining time is
     * planned_seconds minus the time since started_at, less paused_seconds (and less the current pause, if paused_at is
     * set). status is running, paused, completed or abandoned. A user has at most one running or paused session.
     */
    public function up(): void
    {
        Schema::create('pomodoro_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('task_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('kind', 12);
            $table->unsignedInteger('planned_seconds');
            $table->timestampTz('started_at');
            $table->timestampTz('paused_at')->nullable();
            $table->unsignedInteger('paused_seconds')->default(0);
            $table->timestampTz('ended_at')->nullable();
            $table->string('status', 10)->default('running');

            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'ended_at']);
            $table->index('task_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pomodoro_sessions');
    }
};
