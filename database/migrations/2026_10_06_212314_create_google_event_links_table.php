<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Remembers which Google event stands for which planner item, so an update patches the same
     * event instead of creating another. kind is session, deadline, routine or occurrence (a moved
     * routine day). item_id has no foreign key on purpose: when the item is deleted the row is kept
     * until the Google event has been removed.
     */
    public function up(): void
    {
        Schema::create('google_event_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('kind', 16);
            $table->unsignedBigInteger('item_id');
            $table->string('google_calendar_id');
            $table->string('google_event_id');
            $table->string('payload_hash', 64)->nullable();
            $table->json('meta')->nullable();

            $table->timestampsTz();

            $table->unique(['user_id', 'kind', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_event_links');
    }
};
