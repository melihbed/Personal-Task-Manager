<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('calendar_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampsTz();
            $table->index(['user_id', 'starts_at']);
            $table->index(['user_id', 'ends_at']);
            $table->index('task_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE calendar_sessions ADD CONSTRAINT calendar_sessions_positive_interval CHECK (ends_at > starts_at)');
        }
    }

    public function down(): void { Schema::dropIfExists('calendar_sessions'); }
};
