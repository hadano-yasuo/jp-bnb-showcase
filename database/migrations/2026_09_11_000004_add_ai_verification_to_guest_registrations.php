<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ゲスト身分証画像を Claude Vision で自動検証した結果を保存するカラムを追加する。
 *
 * - id_image_verdict: pending / ok / suspicious / invalid / error
 * - id_image_ai_notes: AI が返した issues（人間が読める理由）
 * - id_image_ai_extracted: JSON 抽出結果（氏名・生年月日・書類種別など）
 * - id_image_verified_at: 検証完了時刻
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->string('id_image_verdict', 16)->nullable()->after('id_image_compressed');
            $table->text('id_image_ai_notes')->nullable()->after('id_image_verdict');
            $table->jsonb('id_image_ai_extracted')->nullable()->after('id_image_ai_notes');
            $table->timestampTz('id_image_verified_at')->nullable()->after('id_image_ai_extracted');
        });
    }

    public function down(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->dropColumn([
                'id_image_verdict',
                'id_image_ai_notes',
                'id_image_ai_extracted',
                'id_image_verified_at',
            ]);
        });
    }
};
