<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type'); // appartement | vakantiehuis | kamer
            $table->boolean('active')->default(true);

            $table->string('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->text('house_rules')->nullable();

            $table->string('address')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('NL');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->unsignedTinyInteger('max_guests')->default(2);
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('beds')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();

            $table->time('check_in_from')->nullable();
            $table->time('check_in_until')->nullable();
            $table->time('check_out_until')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
