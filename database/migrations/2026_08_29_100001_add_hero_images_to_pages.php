<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hero photos per page (ported from devsite): sorted list with, per photo, a
 * title (per language), link, title position and optional mobile variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('hero_images')->nullable()->after('content_translations');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('hero_images');
        });
    }
};
