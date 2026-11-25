<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Errors the website ran into, one row per kind of error (same place, same
 * kind) with how often and when, for the daily email to the owner. Kept for
 * a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('level', 20);
            $table->text('message');
            $table->string('location')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
