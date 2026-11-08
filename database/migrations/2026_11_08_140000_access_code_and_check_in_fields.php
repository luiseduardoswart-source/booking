<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Access code for self check-in: none, a fixed code, or the last four digits
 *   of the guest's phone number (empty = follow the default). The fixed code
 *   is stored encrypted.
 * - Own check-in fields (for example a WiFi password or parking code): the
 *   names and default values on the defaults record, an own value per
 *   accommodation. Both encrypted; each field becomes a merge field in the
 *   automatic mails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('access_code_kind', 10)->nullable()->after('check_in_host_id');
            $table->text('access_code')->nullable()->after('access_code_kind');
            $table->text('check_in_fields')->nullable()->after('access_code');
            $table->text('check_in_values')->nullable()->after('check_in_fields');
        });

        DB::table('properties')->where('is_master', true)->update(['access_code_kind' => 'none']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['access_code_kind', 'access_code', 'check_in_fields', 'check_in_values']);
        });
    }
};
