<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the guest signs when booking: only when the rental terms apply (as
 * before) or at every booking. Empty on an accommodation = follow the
 * default; the default keeps what it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('signature_moment', 20)->nullable()->after('id_cancel_refund');
        });

        DB::table('properties')->where('is_master', true)->update(['signature_moment' => 'rental_terms']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('signature_moment');
        });
    }
};
