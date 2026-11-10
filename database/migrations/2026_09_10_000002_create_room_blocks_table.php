<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * beds24 のカレンダー機能で availability=false に設定された日を保存する。
 *
 * beds24 側にはブロック情報を保持する専用エンドポイントが無く、
 * `/inventory/rooms/availability` の false 日から実予約を差し引いて
 * 逆算する必要がある。SyncRoomBlocksJob が定期的に upsert する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('beds24_room_id');
            $table->date('date');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['beds24_room_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_blocks');
    }
};
