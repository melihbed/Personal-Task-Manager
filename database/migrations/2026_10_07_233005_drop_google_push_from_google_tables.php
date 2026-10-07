<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The app no longer sends anything to Google Calendar: Google is only a place to bring events in from. So the record of
     * what was sent, the calendar it was sent to and the switches for what to send are gone.
     */
    public function up(): void
    {
        Schema::dropIfExists('google_event_links');

        Schema::table('google_accounts', function (Blueprint $table) {
            $table->dropColumn(['calendar_id', 'calendar_name', 'push_sessions', 'push_routines', 'push_deadlines']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->string('calendar_id')->nullable();
            $table->string('calendar_name')->nullable();
            $table->boolean('push_sessions')->default(true);
            $table->boolean('push_routines')->default(true);
            $table->boolean('push_deadlines')->default(true);
        });

        Schema::create('google_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('item_id');
            $table->string('google_calendar_id');
            $table->string('google_event_id');
            $table->json('meta')->nullable();
            $table->string('fingerprint')->nullable();
            $table->timestampsTz();
        });
    }
};
