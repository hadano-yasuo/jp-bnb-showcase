<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自動送信メッセージのルール定義。
 *
 * Airbnb のスケジュールメッセージ体系を踏襲:
 *   trigger_event = 'booking_created' → offset_minutes を使う（0=即時, 60=1時間後）
 *   trigger_event = 'checkin' / 'checkout' → offset_days + time_of_day
 *
 * is_enabled=false（default）は「dry-run のみ、実送信しない」。
 * 実運用切替は明示指示があるまで行わない（memory: feedback_execution_off_by_default）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_message_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('trigger_event', 32)->index();
            $table->integer('offset_minutes')->nullable()
                ->comment('booking_created 用（例: 0=即時, 60=1時間後）');
            $table->integer('offset_days')->nullable()
                ->comment('checkin/checkout 用（負=前, 0=当日, 正=後）');
            $table->string('time_of_day', 5)->nullable()
                ->comment('HH:MM（checkin/checkout 用、Asia/Tokyo）');
            $table->string('channel_scope', 32)->default('airbnb')->index()
                ->comment('all | airbnb | booking | direct 等（bookings.api_source と対応）');
            $table->string('locale', 8)->nullable()
                ->comment('ja / en / null=自動');
            $table->text('body')->comment('Blade 記法の本文');
            $table->boolean('is_enabled')->default(false)->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_message_rules');
    }
};
