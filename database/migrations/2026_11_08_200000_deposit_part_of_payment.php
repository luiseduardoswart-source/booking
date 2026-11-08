<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit right after booking: the guest pays the stay and the deposit in one
 * checkout. This column says which part of that payment is the deposit, so
 * the stay amounts, revenue and the deposit refund stay apart. On a refund it
 * marks the refund of (part of) that deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('deposit_part_cents')->default(0)->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('deposit_part_cents');
        });
    }
};
