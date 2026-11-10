<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            // ルール利用開始日時。この時刻より前に予約作成された予約 (booking_time)
            // には発火させない。null なら「未有効化」として全発火をブロックする
            // (安全側デフォルト)。新規ルール作成時は now() をデフォルトで入れる。
            $table->timestampTz('enabled_from')->nullable()->after('only_when_staying');
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->dropColumn('enabled_from');
        });
    }
};
