<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin gets its own languages, separate from those of the website. Every
 * language the site knows is carried over and enabled, even if it is disabled on
 * the site: someone may be working in it right now. The default is the language
 * of the installation, which also applied so far to users without their own language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort')->default(0);
        });

        $languages = DB::table('languages')->orderBy('sort')->orderBy('id')->get();
        $default = $languages->contains('code', config('app.locale')) ? config('app.locale') : $languages->first()?->code;

        DB::table('admin_languages')->insert($languages->map(fn (object $language) => [
            'code' => $language->code,
            'name' => $language->name,
            'active' => true,
            'is_default' => $language->code === $default,
            'sort' => $language->sort,
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_languages');
    }
};
