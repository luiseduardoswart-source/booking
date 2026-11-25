<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cleaners: who cleans which accommodations. They get the changeovers by
 * email and on a private page, without an account. link_version makes an
 * old link stop working when the owner gives them a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('locale', 5)->default('en');
            $table->string('frequency', 10)->default('daily'); // daily | weekly
            $table->boolean('active')->default(true);
            $table->unsignedInteger('link_version')->default(1);
            $table->timestamps();
        });

        Schema::create('cleaner_property', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleaner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->unique(['cleaner_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaner_property');
        Schema::dropIfExists('cleaners');
    }
};
