<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the tourist tax is charged: per person per night, per night, or once
 * per stay. Empty on an accommodation = follow the default. The default keeps
 * what it did: per person per night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('tourist_tax_basis', 20)->nullable()->after('tourist_tax_cents');
        });

        DB::table('properties')->where('is_master', true)->update(['tourist_tax_basis' => 'person_night']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('tourist_tax_basis');
        });
    }
};
