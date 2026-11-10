<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者による身分証画像の verdict 上書きを可能にするカラムを追加。
 *
 * AI が invalid/suspicious と判定しても、管理者が目視確認して問題無ければ
 * admin_verdict='ok' で承認できる。逆にAI が ok でも管理者判断で invalid に
 * 却下することも可能。effective verdict は admin_verdict を優先する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->string('admin_verdict', 16)->nullable()->after('id_image_verified_at');
            $table->text('admin_verdict_reason')->nullable()->after('admin_verdict');
            $table->timestampTz('admin_reviewed_at')->nullable()->after('admin_verdict_reason');
            $table->foreignId('admin_reviewed_by')->nullable()->constrained('users')->nullOnDelete()->after('admin_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->dropForeign(['admin_reviewed_by']);
            $table->dropColumn(['admin_verdict', 'admin_verdict_reason', 'admin_reviewed_at', 'admin_reviewed_by']);
        });
    }
};
