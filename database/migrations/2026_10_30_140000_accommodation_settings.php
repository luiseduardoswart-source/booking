<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment rules, deposit rules, mail scheduling, mail texts and the dynamic
 * pricing service now apply per accommodation. Empty = follow the default.
 * The general settings move to the master record, so only one source
 * remains.
 */
return new class extends Migration
{
    /** Column => [group, key, value if the setting was never filled in]. */
    private const FIELDS = [
        'down_payment_percentage' => ['payments', 'down_payment_percentage', 30],
        'vat_percentage' => ['tax', 'vat_percentage', 9],
        'deposit_days_before_arrival' => ['mail', 'deposit_days_before_arrival', 7],
        'deposit_days_after_departure' => ['deposit', 'days_after_departure', 3],
        'deposit_auto_refund' => ['deposit', 'auto_refund', 0],
        'pricing_service' => ['prices', 'service', 'none'],
        'balance_payment_days_before_arrival' => ['mail', 'balance_payment_days_before_arrival', 14],
        'checkin_days_before_arrival' => ['mail', 'checkin_days_before_arrival', 3],
        'review_days_after_departure' => ['mail', 'review_days_after_departure', 2],
    ];

    private const MAIL_TYPES = [
        'confirmation', 'payment_received', 'balance_payment', 'deposit', 'payment_request',
        'verified', 'checkin', 'checkout', 'review',
    ];

    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedTinyInteger('down_payment_percentage')->nullable()->after('deposit_cents');
            $table->unsignedTinyInteger('vat_percentage')->nullable()->after('down_payment_percentage');
            $table->unsignedSmallInteger('deposit_days_before_arrival')->nullable()->after('vat_percentage');
            $table->unsignedSmallInteger('deposit_days_after_departure')->nullable()->after('deposit_days_before_arrival');
            $table->boolean('deposit_auto_refund')->nullable()->after('deposit_days_after_departure');
            $table->string('pricing_service', 20)->nullable()->after('external_listing_id');
            $table->unsignedSmallInteger('balance_payment_days_before_arrival')->nullable()->after('deposit_auto_refund');
            $table->unsignedSmallInteger('checkin_days_before_arrival')->nullable()->after('balance_payment_days_before_arrival');
            $table->unsignedSmallInteger('review_days_after_departure')->nullable()->after('checkin_days_before_arrival');
            $table->json('mail_texts')->nullable()->after('review_days_after_departure');
        });

        $this->moveSettings();
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([...array_keys(self::FIELDS), 'mail_texts']);
        });
    }

    private function moveSettings(): void
    {
        $settings = DB::table('settings')->get(['group', 'key', 'value'])
            ->mapWithKeys(fn (object $row) => ["{$row->group}.{$row->key}" => $row->value]);

        $values = [];

        foreach (self::FIELDS as $column => [$group, $key, $default]) {
            $value = $settings["{$group}.{$key}"] ?? null;
            $values[$column] = blank($value) ? $default : $value;
        }

        $values['mail_texts'] = json_encode($this->mailTexts($settings->all()), JSON_UNESCAPED_UNICODE);

        // Without a master record, Property::master() creates it later with the starting values.
        DB::table('properties')->where('is_master', true)->update($values);

        foreach (self::FIELDS as [$group, $key]) {
            DB::table('settings')->where('group', $group)->where('key', $key)->delete();
        }

        DB::table('settings')->where('group', 'mail')->where('key', 'like', 'text%')->delete();

        Setting::forgets();
    }

    /**
     * From text_<type>_<language> to <type> => <language> => text. An old text
     * without a language was Dutch; a text per language always takes precedence.
     *
     * @param  array<string, ?string>  $settings
     * @return array<string, array<string, string>>
     */
    private function mailTexts(array $settings): array
    {
        $texts = [];

        foreach (self::MAIL_TYPES as $type) {
            $prefix = "mail.text_{$type}";

            foreach ($settings as $key => $text) {
                if (filled($text) && str_starts_with($key, "{$prefix}_")) {
                    $texts[$type][substr($key, strlen($prefix) + 1)] = $text;
                }
            }

            if (filled($settings[$prefix] ?? null) && blank($texts[$type]['nl'] ?? null)) {
                $texts[$type]['nl'] = $settings[$prefix];
            }
        }

        return $texts;
    }
};
