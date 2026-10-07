<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user's Pomodoro rhythm. Without a row, the defaults (50 minute focus, 10 minute break, 30 minute long break
     * after 3 rounds) apply.
     */
    public function up(): void
    {
        Schema::create('pomodoro_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedSmallInteger('focus_minutes')->default(50);
            $table->unsignedSmallInteger('short_break_minutes')->default(10);
            $table->unsignedSmallInteger('long_break_minutes')->default(30);
            $table->unsignedTinyInteger('rounds_before_long')->default(3);
            $table->boolean('sound_enabled')->default(true);

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pomodoro_settings');
    }
};
