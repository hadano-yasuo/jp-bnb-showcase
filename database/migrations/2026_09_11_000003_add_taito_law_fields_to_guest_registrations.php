<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 台東区旅館業のてびき（令和7年4月版）14ページの宿泊者名簿記載事項に合わせて、
 * 現在のスキーマに不足していた項目を追加する。
 *
 * - sex: 全員必須（法6-1 細7 ④）
 * - is_representative: 代表者フラグ。ON の人だけが到着日時・出発日時・電話番号を持つ
 * - arrival_at / departure_at: 代表者のみ入力（法6-1 細7 ⑧⑨）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->string('sex', 16)->nullable()->after('nationality');
            $table->boolean('is_representative')->default(false)->after('sex');
            $table->timestampTz('arrival_at')->nullable()->after('is_representative');
            $table->timestampTz('departure_at')->nullable()->after('arrival_at');
        });
    }

    public function down(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->dropColumn(['sex', 'is_representative', 'arrival_at', 'departure_at']);
        });
    }
};
