<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A name to show for rows of kind "hidden": events the user hid from the planner calendar, listed in settings
     * so they can be shown again.
     */
    public function up(): void
    {
        Schema::table('google_event_imports', function (Blueprint $table) {
            $table->string('label')->nullable()->after('item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_event_imports', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
