<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();       // lives at root level, next to the accommodation slugs
            $table->string('title');                // Dutch title
            $table->json('title_translations')->nullable();
            $table->longText('content')->nullable(); // Dutch content (rich text)
            $table->json('content_translations')->nullable();
            $table->string('meta_title')->nullable();
            $table->json('meta_title_translations')->nullable();
            $table->string('meta_description')->nullable();
            $table->json('meta_description_translations')->nullable();
            $table->boolean('published')->default(true);
            $table->boolean('in_menu')->default(true);   // link in the header
            $table->boolean('in_footer')->default(false); // link in the footer
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
