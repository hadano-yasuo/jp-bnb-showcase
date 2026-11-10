<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sex カラム幅拡張。VARCHAR(16) では 'prefer_not_to_say' (17 文字) が入らず
 * QueryException になっていた。将来値の追加余地も見て VARCHAR(32) に。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->string('sex', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('guest_registrations', function (Blueprint $table): void {
            $table->string('sex', 16)->nullable()->change();
        });
    }
};
