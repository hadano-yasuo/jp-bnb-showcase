<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ゲスト身分証画像の重複検出用に SHA-256 ハッシュカラムを追加。
 *
 * - 完全一致（バイト同じファイル）の再アップロード検知に使用
 * - 別予約横断でも同じファイルが使い回されていないかを Filament で警告
 * - AI 抽出氏名 (id_image_ai_extracted->>extracted_name) との併用で
 *   「同じ人物の別ショット」も検出できる
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->string('id_image_sha256', 64)->nullable()->after('id_image_path');
            $table->index('id_image_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->dropIndex(['id_image_sha256']);
            $table->dropColumn('id_image_sha256');
        });
    }
};
