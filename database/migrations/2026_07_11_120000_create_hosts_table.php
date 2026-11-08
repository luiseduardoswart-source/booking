<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('bio')->nullable();
            $table->timestamps();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('host_id')->nullable()->after('ical_token')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_id');
        });
        Schema::dropIfExists('hosts');
    }
};
