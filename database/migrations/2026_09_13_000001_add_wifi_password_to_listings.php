<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自動送信メッセージの本文で {{ listing.wifi_password }} を展開できるように
 * listings に wifi_password カラムを追加。
 * 他の部屋差分（暗証番号等）が必要になったら都度カラムを足す方針。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('wifi_password', 128)->nullable()->after('airbnb_url');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('wifi_password');
        });
    }
};
