<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // One hidden master record carries the default prices and rules
            // for all properties; each property can override them.
            $table->boolean('is_master')->default(false)->after('id');

            // Price fields nullable: empty = use the default setting.
            $table->unsignedInteger('base_price_cents')->nullable()->change();
            $table->unsignedInteger('cleaning_fee_cents')->nullable()->change();
            $table->unsignedInteger('tourist_tax_cents')->nullable()->change();
            $table->unsignedInteger('extra_guest_fee_cents')->nullable()->change();
            $table->unsignedInteger('deposit_cents')->nullable()->change();
            $table->string('cancellation_policy')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('is_master');
        });
    }
};
