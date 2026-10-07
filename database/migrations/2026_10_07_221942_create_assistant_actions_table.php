<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A record of what happened to every change the assistant suggested: applied, failed or dismissed. It answers "what
     * happened to my task?", so it outlives the chat (starting a new chat keeps it) and the thing it changed (subject_id
     * has no foreign key, and subject_title keeps the name). changes lists each field as the user saw it, from and to.
     */
    public function up(): void
    {
        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('assistant_message_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('type', 24);
            $table->string('status', 10);
            $table->text('summary');
            $table->text('result')->nullable();

            $table->string('subject_type', 12)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_title')->nullable();
            $table->json('changes')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
    }
};
