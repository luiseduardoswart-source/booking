<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A discount from X nights can now also be a fixed amount, besides a
 * percentage. Existing discounts stay percentages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            $table->string('type', 10)->default('percent')->after('min_nights');
            $table->unsignedTinyInteger('percentage')->nullable()->change();
            $table->unsignedInteger('amount_cents')->nullable()->after('percentage');
        });
    }

    public function down(): void
    {
        // A fixed amount has no percentage; those rows cannot go back.
        DB::table('discounts')->where('type', 'fixed')->delete();

        Schema::table('discounts', function (Blueprint $table) {
            $table->dropColumn(['type', 'amount_cents']);
            $table->unsignedTinyInteger('percentage')->nullable(false)->change();
        });
    }
};
