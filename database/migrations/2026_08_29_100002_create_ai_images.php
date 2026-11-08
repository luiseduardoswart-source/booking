<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_images', function (Blueprint $table) {
            $table->id();
            $table->text('prompt');
            $table->string('path')->nullable();       // pending file or (after approval) media path
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->string('model')->nullable();
            $table->string('size')->nullable();
            $table->string('month', 7)->index();       // YYYY-MM, for the monthly limit
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_images');
    }
};
