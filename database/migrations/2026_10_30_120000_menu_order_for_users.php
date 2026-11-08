<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order in which someone dragged the left-hand menu in the admin:
 * a list of paths (e.g. /admin/bookings). Empty = the default order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('menu_order')->nullable()->after('permissions');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('menu_order');
        });
    }
};
