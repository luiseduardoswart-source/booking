<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Discount on the dynamic price: a percentage or a fixed amount off the
 *   price of PriceLabs (or another service), for example the surcharge that
 *   covers the Airbnb or Vrbo commission (empty = follow the default).
 * - Whether a price set by hand in the calendar goes before the dynamic price
 *   (empty = follow the default).
 *
 * What was already there keeps working the same: the default starts without
 * discount, and the dynamic price keeps overwriting manual prices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('dynamic_discount_kind', 10)->nullable()->after('pricing_service');
            $table->decimal('dynamic_discount_percentage', 5, 2)->nullable()->after('dynamic_discount_kind');
            $table->unsignedInteger('dynamic_discount_cents')->nullable()->after('dynamic_discount_percentage');
            $table->boolean('manual_prices_win')->nullable()->after('dynamic_discount_cents');
        });

        DB::table('properties')->where('is_master', true)
            ->update(['dynamic_discount_kind' => 'none', 'manual_prices_win' => false]);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['dynamic_discount_kind', 'dynamic_discount_percentage', 'dynamic_discount_cents', 'manual_prices_win']);
        });
    }
};
