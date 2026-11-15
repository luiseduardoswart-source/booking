<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ID verification can also come after the payment, before arrival: a
 * deadline in days before arrival, a reminder before it, and what happens
 * when it is missed (cancel or notify the owner, and what is refunded).
 * Empty on an accommodation = follow the default. The default keeps what
 * it did: the check before the payment.
 *
 * On the booking: when the missed deadline was dealt with, so it happens once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('id_moment', 20)->nullable()->after('id_required');
            $table->unsignedSmallInteger('id_deadline_days')->nullable()->after('id_moment');
            $table->unsignedSmallInteger('id_reminder_days')->nullable()->after('id_deadline_days');
            $table->string('id_overdue_action', 20)->nullable()->after('id_reminder_days');
            $table->string('id_cancel_refund', 20)->nullable()->after('id_overdue_action');
        });

        DB::table('properties')->where('is_master', true)->update([
            'id_moment' => 'before_payment',
            'id_deadline_days' => 7,
            'id_reminder_days' => 3,
            'id_overdue_action' => 'notify',
            'id_cancel_refund' => 'manual',
        ]);

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('identity_overdue_at')->nullable()->after('identity_status');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['id_moment', 'id_deadline_days', 'id_reminder_days', 'id_overdue_action', 'id_cancel_refund']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('identity_overdue_at');
        });
    }
};
