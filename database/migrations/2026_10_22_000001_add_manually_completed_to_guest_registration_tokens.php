<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メール等の別ルートでゲスト情報を受領した予約を、管理者が手動で「収集完了」扱いに
 * できるようにするカラムを追加。guest_registrations 行は作らずフラグだけ立てる運用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_registration_tokens', function (Blueprint $table): void {
            $table->timestampTz('manually_completed_at')->nullable()->after('expires_at');
            $table->foreignId('manually_completed_by')->nullable()->constrained('users')->nullOnDelete()->after('manually_completed_at');
            $table->text('manually_completed_note')->nullable()->after('manually_completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('guest_registration_tokens', function (Blueprint $table): void {
            $table->dropForeign(['manually_completed_by']);
            $table->dropColumn(['manually_completed_at', 'manually_completed_by', 'manually_completed_note']);
        });
    }
};
