<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            // Only the bio is translatable; a host's name is a proper
            // name and stays the same in every language.
            $table->json('bio_translations')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('hosts', function (Blueprint $table) {
            $table->dropColumn('bio_translations');
        });
    }
};
