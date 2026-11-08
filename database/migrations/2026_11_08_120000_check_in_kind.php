<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How the guest checks in: by themselves (key box, code or smart lock) or
 * welcomed in person by one of the hosts (empty = follow the default).
 *
 * What was already there looks the same: the default starts at self check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('check_in_kind', 10)->nullable()->after('check_out_until');
            $table->foreignId('check_in_host_id')->nullable()->after('check_in_kind')->constrained('hosts')->nullOnDelete();
        });

        DB::table('properties')->where('is_master', true)->update(['check_in_kind' => 'self']);
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('check_in_host_id');
            $table->dropColumn('check_in_kind');
        });
    }
};
