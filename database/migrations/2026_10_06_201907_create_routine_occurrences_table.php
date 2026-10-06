<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Only exceptions to a routine are stored: a skipped day, a day marked done, or a day moved
     * to another time. occurs_on is the original date of the occurrence in the routine's timezone.
     */
    public function up(): void
    {
        Schema::create('routine_occurrences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('routine_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('occurs_on');
            $table->boolean('skipped')->default(false);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();

            $table->timestampsTz();

            $table->unique(['routine_id', 'occurs_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routine_occurrences');
    }
};
