<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Door codes from a smart lock (through Seam): per accommodation the lock and
 * the time zone the lock lives in (check-in and check-out are local times);
 * per booking the code the lock got for that stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('smart_lock_device_id', 64)->nullable()->after('access_code');
            $table->string('time_zone', 64)->nullable()->after('smart_lock_device_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('lock_code_id', 64)->nullable();
            $table->text('lock_code')->nullable();
            $table->index('lock_code_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['lock_code_id']);
            $table->dropColumn(['lock_code_id', 'lock_code']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['smart_lock_device_id', 'time_zone']);
        });
    }
};
