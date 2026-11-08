<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the guest pays the deposit: right after booking, or a number of days
 * before arrival (empty = follow the default). The deposit stays a separate
 * payment, never part of the price. The default keeps what it did: days
 * before arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('deposit_moment', 20)->nullable()->after('deposit_auto_refund');
        });

        DB::table('properties')->where('is_master', true)->update(['deposit_moment' => 'before_arrival']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('deposit_moment');
        });
    }
};
