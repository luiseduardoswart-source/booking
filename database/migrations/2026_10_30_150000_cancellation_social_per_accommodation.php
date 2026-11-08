<?php

use App\Models\Property;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ID verification, price in the calendar, social links and the texts of the fixed
 * cancellation terms now apply via the defaults, with
 * its own value per accommodation. First the master record is updated,
 * only then do the old settings disappear.
 */
return new class extends Migration
{
    private const POLICY = ['flexible', 'moderate', 'strict'];

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('cancellation_texts')->nullable()->after('mail_texts');
        });

        $this->moveSettings();
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('cancellation_texts');
        });
    }

    private function moveSettings(): void
    {
        $old = DB::table('settings')
            ->where(fn ($query) => $query
                ->whereIn('group', ['social', 'cancellation'])
                ->orWhere(fn ($row) => $row->where('group', 'verification')->where('key', 'id_required'))
                ->orWhere(fn ($row) => $row->where('group', 'site')->where('key', 'prices_in_calendar')))
            ->get(['id', 'group', 'key', 'value']);

        if ($old->isEmpty()) {
            return;
        }

        $settings = $old->mapWithKeys(fn (object $row) => ["{$row->group}.{$row->key}" => $row->value]);

        // Before master(): that fills a new record with the starting values.
        $existing = DB::table('properties')->where('is_master', true)->first();
        $master = Property::master();

        $links = $old->where('group', 'social')->filter(fn (object $row) => filled($row->value))->pluck('value', 'key')->all();
        $ownLinks = json_decode((string) ($existing->social_links ?? ''), true) ?: [];

        DB::table('properties')->where('id', $master->id)->update([
            'id_required' => $existing->id_required ?? (int) ($settings['verification.id_required'] ?? 0),
            'prices_in_calendar' => $existing->prices_in_calendar ?? (int) ($settings['site.prices_in_calendar'] ?? 0),
            'social_links' => json_encode([...$links, ...$ownLinks], JSON_UNESCAPED_UNICODE),
            'cancellation_texts' => json_encode($this->cancellationTexts($settings->all()), JSON_UNESCAPED_UNICODE),
        ]);

        DB::table('settings')->whereIn('id', $old->pluck('id'))->delete();

        Setting::forgets();
    }

    /**
     * From text_<policy>_<language> to <policy> => <language> => text.
     *
     * @param  array<string, ?string>  $settings
     * @return array<string, array<string, string>>
     */
    private function cancellationTexts(array $settings): array
    {
        $texts = [];

        foreach (self::POLICY as $policy) {
            $prefix = "cancellation.text_{$policy}";

            foreach ($settings as $key => $text) {
                if (filled($text) && str_starts_with($key, "{$prefix}_")) {
                    $texts[$policy][substr($key, strlen($prefix) + 1)] = $text;
                }
            }

            if (filled($settings[$prefix] ?? null) && blank($texts[$policy]['nl'] ?? null)) {
                $texts[$policy]['nl'] = $settings[$prefix];
            }
        }

        return $texts;
    }
};
