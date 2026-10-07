<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The app's own events: something that happens at a time, such as a meeting or an appointment. A timed event uses
     * starts_at and ends_at. An all-day event uses starts_on and ends_on, the first and last day (inclusive), so it stays on
     * the same days whatever timezone the calendar is shown in.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('responsibility_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('title');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('all_day')->default(false);

            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'starts_at']);
            $table->index(['user_id', 'starts_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
