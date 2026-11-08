<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users for the roles system:
 * - host_id: links a login to a host profile; such a user sees
 *   only that host's accommodations.
 * - permissions: the allowed actions per screen (see App\Support\Access).
 *   Empty for a staff member = everything, as it was before permissions.
 * - password_set_at: empty as long as an invited user has not yet
 *   chosen their own password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('host_id')->nullable()->after('role')->constrained()->nullOnDelete();
            $table->json('permissions')->nullable()->after('host_id');
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        // Existing accounts chose their own password.
        DB::table('users')->update(['password_set_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
            $table->dropColumn(['permissions', 'password_set_at']);
        });
    }
};
