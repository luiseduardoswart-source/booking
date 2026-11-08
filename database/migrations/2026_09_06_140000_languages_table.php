<?php

use App\Models\Language;
use App\Models\Setting;
use App\Support\Languages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Languages were fixed in config/app.php and could only be switched on or off.
 * They now get their own table, so the owner can add, rename and delete
 * languages — as in the devsite project.
 *
 * The existing situation moves along: which languages were on and which was
 * the default was stored in the settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 5)->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort')->default(0);
        });

        $active = $this->activeCodes();
        $default = Setting::query()->where('group', 'site')->where('key', 'default_language')->value('value')
            ?: config('app.locale');

        foreach (config('app.supported_locales') as $i => $code) {
            Language::create([
                'code' => $code,
                'name' => Languages::NAMES[$code] ?? strtoupper($code),
                'active' => in_array($code, $active, true),
                'is_default' => $code === $default,
                'sort' => $i,
            ]);
        }

        // Without a default language the site would have nothing to fall back on.
        if (! Language::where('is_default', true)->exists()) {
            Language::query()->orderBy('sort')->first()?->update(['active' => true, 'is_default' => true]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function activeCodes(): array
    {
        $value = (string) Setting::query()->where('group', 'site')->where('key', 'languages')->value('value');

        if (trim($value) === '') {
            return config('app.supported_locales');
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
