<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One Canvas connection per user. The personal access token is a full-power credential, so it is
     * encrypted by the model cast and the app only ever reads from Canvas with it.
     */
    public function up(): void
    {
        Schema::create('canvas_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('base_url');
            $table->text('access_token');
            $table->string('canvas_user_name')->nullable();
            $table->boolean('needs_reconnect')->default(false);

            $table->foreignId('responsibility_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestampTz('last_synced_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canvas_accounts');
    }
};
