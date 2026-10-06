<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A routine is a recurring calendar block stored once as a rule: the days of the week
     * (ISO, 1 = Monday ... 7 = Sunday), a wall-clock start time in its own timezone, and a length.
     * Occurrences are computed when the calendar is viewed, never stored in advance.
     */
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('responsibility_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('title');
            $table->json('days');
            $table->time('start_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->timestampsTz();

            $table->index('user_id');
            $table->index('responsibility_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
