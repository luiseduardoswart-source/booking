<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Alle bedragen in centen (integers), nooit floats.
            $table->unsignedInteger('base_price_cents')->default(10000)->after('max_guests');
            $table->unsignedInteger('weekend_price_cents')->nullable()->after('base_price_cents');
            $table->unsignedInteger('cleaning_fee_cents')->default(0)->after('weekend_price_cents');
            $table->unsignedInteger('tourist_tax_cents')->default(0)->after('cleaning_fee_cents'); // p.p. per nacht
            $table->unsignedInteger('extra_guest_fee_cents')->default(0)->after('tourist_tax_cents'); // p.p. per nacht
            $table->unsignedTinyInteger('included_guests')->nullable()->after('extra_guest_fee_cents');
            $table->unsignedInteger('deposit_cents')->default(0)->after('included_guests'); // borg
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'base_price_cents', 'weekend_price_cents', 'cleaning_fee_cents',
                'tourist_tax_cents', 'extra_guest_fee_cents', 'included_guests', 'deposit_cents',
            ]);
        });
    }
};
