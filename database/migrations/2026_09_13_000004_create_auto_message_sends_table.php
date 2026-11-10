<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ルール × 予約 単位の送信予定キュー。
 *
 * status 遷移:
 *   pending → dry_run   (rule.is_enabled=false のまま発火時刻を過ぎた)
 *   pending → sent      (実送信成功)
 *   pending → skipped   (キャンセル済み / arrival 過ぎ / channel 不一致 等)
 *   pending → failed    (実送信失敗、後続で retry 対応)
 *
 * unique(auto_message_rule_id, booking_id) で二重送信防止（同じ予約 × 同じルールは 1 回のみ）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_message_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_message_rule_id')
                ->constrained('auto_message_rules')
                ->cascadeOnDelete();
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->timestampTz('scheduled_at')->index();
            $table->string('status', 16)->default('pending')->index();
            $table->string('skip_reason', 64)->nullable();
            $table->text('rendered_body')->nullable();
            $table->unsignedBigInteger('beds24_message_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->unique(['auto_message_rule_id', 'booking_id']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_message_sends');
    }
};
