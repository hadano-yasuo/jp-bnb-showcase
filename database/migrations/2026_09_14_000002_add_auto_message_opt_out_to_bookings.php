<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 予約単位で自動送信メッセージを無効化するフラグ。
 * 会話ページのチェックボックスから ON/OFF する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('auto_message_opt_out')->default(false)->after('modified_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('auto_message_opt_out');
        });
    }
};
