<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * booking.email は beds24 が持ってくる連絡先 (OTA によっては匿名化アドレス、
 * 例: @guests.airbnb.com)。ホストがゲストから別途受け取った実連絡先を
 * 保存する専用カラム。beds24 sync では上書きされない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('contact_email', 255)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('contact_email');
        });
    }
};
