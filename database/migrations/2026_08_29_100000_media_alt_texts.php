<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alt texts per media file. Only manually edited texts are stored here;
 * files without a row automatically get an alt from their file name (see media_alt()).
 * That way all images have an alt, even when nothing has been filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_alts', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();   // path within the public disk, e.g. media/foto.jpg
            $table->string('alt', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_alts');
    }
};
