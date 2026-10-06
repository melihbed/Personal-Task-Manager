<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One connected Google account per user. Tokens are encrypted by the model cast.
     * calendar_id is where planner items are pushed; import_calendar_ids are the calendars whose
     * events are shown (read-only) on the planner calendar.
     */
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('needs_reconnect')->default(false);

            $table->string('calendar_id')->nullable();
            $table->string('calendar_name')->nullable();
            $table->json('import_calendar_ids')->nullable();

            $table->boolean('push_sessions')->default(true);
            $table->boolean('push_routines')->default(true);
            $table->boolean('push_deadlines')->default(true);

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
