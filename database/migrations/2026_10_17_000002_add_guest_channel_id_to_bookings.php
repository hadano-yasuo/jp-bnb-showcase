<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自社サイトからの直接予約で、問い合わせ (GuestChannel) 由来の場合に
 * その problem channel を予約にリンクするためのカラム。
 *
 * ShowConversation 画面でこのリンクを辿って GuestChannelMessage を取得し、
 * 「問い合わせ履歴」として予約確定 Message の前に表示する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('guest_channel_id')
                ->nullable()
                ->after('auto_message_opt_out')
                ->constrained('guest_channels')
                ->nullOnDelete()
                ->comment('問い合わせ由来の直接予約で、問い合わせ時の GuestChannel を参照');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_channel_id');
        });
    }
};
