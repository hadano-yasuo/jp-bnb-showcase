<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('guest_channels', function (Blueprint $table) {
            $table->string('room_slug', 50)->nullable()->after('guest_locale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_channels', function (Blueprint $table) {
            $table->dropColumn('room_slug');
        });
    }
};
