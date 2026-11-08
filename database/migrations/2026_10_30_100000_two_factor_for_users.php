<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-factor authentication with an authenticator app (Filament). Both columns
 * are stored encrypted (casts in Filament's traits on the User model).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
