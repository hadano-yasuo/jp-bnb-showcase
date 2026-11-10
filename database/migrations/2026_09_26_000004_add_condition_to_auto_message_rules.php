<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 発火条件 (condition) を追加。null=無条件、registration_incomplete=
 * ゲスト情報登録が未完了なときだけ発火。Dispatcher が dispatch 時に評価する。
 *
 * 未登録・人数不足・非日本人 id 欠落を単一の bool にまとめる方針で、
 * 判定は Booking::isRegistrationIncomplete() に集約する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->string('condition', 32)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('auto_message_rules', function (Blueprint $table) {
            $table->dropColumn('condition');
        });
    }
};
