<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GuestChannel 内の 1 メッセージ。
 * source: guest / host / system の 3 種類。
 * 問い合わせフォーム経由の初回は source=guest、直接予約フォーム経由の
 * 初回は source=system (「予約リクエストを受け付けました…」)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_channel_id')
                ->constrained('guest_channels')
                ->cascadeOnDelete();
            $table->string('source', 16); // guest / host / system
            $table->text('message');
            $table->timestampTz('sent_at')->index();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_channel_messages');
    }
};
