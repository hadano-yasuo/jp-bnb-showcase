<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * beds24 の cancelTime を保存するカラム。
 * キャンセル時トリガー（trigger_event = 'cancelled'）の起点として使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('cancel_time')->nullable()->after('modified_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('cancel_time');
        });
    }
};
