<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The courses Canvas lists as active for the user. Only tracked courses are synced.
     */
    public function up(): void
    {
        Schema::create('canvas_courses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('canvas_id');
            $table->string('name');
            $table->string('course_code')->nullable();
            $table->string('term')->nullable();
            $table->boolean('tracked')->default(true);

            $table->timestampsTz();

            $table->unique(['user_id', 'canvas_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canvas_courses');
    }
};
