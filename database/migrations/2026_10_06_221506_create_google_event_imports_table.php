<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Remembers which Google event a planner item was copied from. It hides the event from the calendar overlay
     * so it is not shown twice, and keeps the item from being pushed back to Google as a duplicate. For a
     * routine, google_event_id is the id of the whole repeating series. item_id has no foreign key: the row is
     * removed when the item is deleted.
     */
    public function up(): void
    {
        Schema::create('google_event_imports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('google_calendar_id');
            $table->string('google_event_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('item_id');

            $table->timestampsTz();

            $table->unique(['user_id', 'google_calendar_id', 'google_event_id']);
            $table->index(['user_id', 'kind', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_event_imports');
    }
};
