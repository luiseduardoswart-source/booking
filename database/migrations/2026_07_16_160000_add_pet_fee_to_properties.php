<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // Toeslag per huisdier (eenmalig per verblijf). Nullable → master-fallback.
            $table->unsignedInteger('pet_fee_cents')->nullable()->after('extra_guest_fee_cents');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('pet_fee_cents');
        });
    }
};
