<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 生成の返信下書き履歴。
 *
 * 1件のメッセージに複数の draft が対応する（生成→気に入らない→再生成の繰り返し）。
 * 返信 UI の「過去の下書き」パネルはこのテーブルを time DESC で取得する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->text('prompt')->nullable();
            $table->text('previous_draft')->nullable();
            $table->string('language', 16);
            $table->string('language_label', 64)->nullable();
            $table->text('draft_text');
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 8, 6)->default(0);
            $table->timestampTz('generated_at');
            $table->timestampsTz();

            $table->index(['message_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_drafts');
    }
};
