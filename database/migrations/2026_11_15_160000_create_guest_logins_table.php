<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My bookings" without a password: a one-time login link by email, and once
 * used a login that the browser remembers. Only a hash of each code is
 * stored, so the table itself gives no access; deleting a row logs out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_logins', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('kind', 10); // link | session
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_logins');
    }
};
