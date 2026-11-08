<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DPGO has no API to fetch prices (it only pushes prices itself into
 * a PMS), so the option disappears. Whoever had chosen DPGO now follows the
 * default; if the default itself was DPGO, it becomes "none". The
 * stored DPGO key can no longer be used for anything afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('properties')->where('is_master', false)->where('pricing_service', 'dpgo')->update(['pricing_service' => null]);
        DB::table('properties')->where('is_master', true)->where('pricing_service', 'dpgo')->update(['pricing_service' => 'none']);

        DB::table('settings')->where('group', 'prices')->where('key', 'dpgo_api_key')->delete();
    }

    public function down(): void
    {
        // Cannot be reversed: which accommodation had DPGO was not kept.
    }
};
