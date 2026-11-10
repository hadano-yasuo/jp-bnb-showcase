<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('beds24_booking_id')->unique();
            $table->unsignedBigInteger('beds24_property_id')->index();
            $table->unsignedBigInteger('beds24_room_id')->index();
            $table->unsignedBigInteger('beds24_master_id')->nullable()->index();
            $table->string('status', 32)->index();
            $table->string('api_source', 64)->nullable()->index();
            $table->string('channel', 32)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable();
            $table->string('mobile', 64)->nullable();
            $table->date('arrival')->index();
            $table->date('departure');
            $table->unsignedSmallInteger('num_adult')->nullable();
            $table->unsignedSmallInteger('num_child')->nullable();
            $table->timestampTz('booking_time')->nullable();
            $table->timestampTz('modified_time')->nullable()->index();
            $table->jsonb('raw_payload');
            $table->timestampTz('fetched_at')->useCurrent();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
