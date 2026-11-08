<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Support\Languages;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/**
 * A form from the form builder: an own form (for example a request form on a
 * page) or one of the two fixed forms, the booking form and the contact form.
 * The fixed forms cannot be deleted and keep their fixed fields; own fields
 * can be added to every form.
 *
 * An own field (in `fields`):
 *   key          fixed once the field exists; answers and merge fields use it
 *   type         one of TYPES
 *   label        language => text (the source language is required)
 *   placeholder  language => text
 *   options      language => one option per line (select, radio, checkboxes)
 *   required     bool
 *   width        'full' or 'half'
 */
#[Fillable([
    'name', 'title', 'title_translations', 'success_message', 'success_message_translations',
    'submit_label', 'submit_label_translations', 'fields', 'hidden_fields', 'recipients', 'active',
])]
class Form extends Model
{
    use HasTranslations;

    public const BOOKING = 'booking';

    public const CONTACT = 'contact';

    /** Field type => label in the admin (translated where it is shown). */
    public const TYPES = [
        'text' => 'Text',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'number' => 'Number',
        'date' => 'Date',
        'textarea' => 'Long text',
        'select' => 'Dropdown list',
        'radio' => 'Choice buttons (one answer)',
        'checkboxes' => 'Checkboxes (several answers)',
        'checkbox' => 'Single checkbox (for example "I agree")',
        'heading' => 'Subheading (no input)',
    ];

    /** Types with a list of options. */
    public const CHOICE_TYPES = ['select', 'radio', 'checkboxes'];

    /** Types with a placeholder text in the input. */
    public const PLACEHOLDER_TYPES = ['text', 'email', 'phone', 'number', 'textarea'];

    /**
     * The fixed fields of the fixed forms: key => [label (language file key),
     * may the owner hide it]. They are built into the booking and contact
     * pages themselves; listed here for the admin and the submissions.
     */
    public const FIXED_FIELDS = [
        self::BOOKING => [
            'name' => ['Full name', false],
            'email' => ['Email address', false],
            'phone' => ['Phone', false],
            'address' => ['Address', true],
            'postal_code' => ['Postal code', true],
            'city' => ['City', true],
            'notes' => ['Remarks', false],
            'terms' => ['I agree to the house rules and cancellation policy.', false],
        ],
        self::CONTACT => [
            'name' => ['Name', false],
            'email' => ['Email address', false],
            'message' => ['Message', false],
        ],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $form): void {
            $form->fields = self::withKeys(is_array($form->fields) ? $form->fields : []);
            $hideable = array_keys(array_filter(self::FIXED_FIELDS[$form->system] ?? [], fn (array $fixed) => $fixed[1]));
            $form->hidden_fields = array_values(array_intersect((array) $form->hidden_fields, $hideable)) ?: null;
        });

        static::deleting(function (self $form): void {
            if ($form->system !== null) {
                throw new LogicException('A fixed form cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'title_translations' => 'array',
            'success_message_translations' => 'array',
            'submit_label_translations' => 'array',
            'fields' => 'array',
            'hidden_fields' => 'array',
            'active' => 'boolean',
        ];
    }

    /** One of the fixed forms; created if it does not exist (yet). */
    public static function fixed(string $system): self
    {
        return static::query()->firstOrCreate(
            ['system' => $system],
            ['name' => $system === self::BOOKING ? 'Booking form' : 'Contact form', 'active' => true],
        );
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function isSystem(): bool
    {
        return $this->system !== null;
    }

    /** Is this fixed field shown? Only the hideable ones can be hidden. */
    public function shows(string $fixedField): bool
    {
        return ! in_array($fixedField, (array) $this->hidden_fields, true);
    }

    /**
     * The own fields, complete and in order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ownFields(): array
    {
        return array_values(array_filter(
            (array) $this->fields,
            fn ($field) => is_array($field) && filled($field['key'] ?? null) && isset(self::TYPES[$field['type'] ?? '']),
        ));
    }

    /** @return array<int, array<string, mixed>> own fields with an input (no subheadings) */
    public function inputFields(): array
    {
        return array_values(array_filter($this->ownFields(), fn (array $field) => $field['type'] !== 'heading'));
    }

    /** The text of a field in a language, falling back to the source language. */
    public static function text(array $field, string $part, ?string $locale = null): string
    {
        $texts = (array) ($field[$part] ?? []);
        $locale ??= app()->getLocale();

        return trim((string) (filled($texts[$locale] ?? null) ? $texts[$locale] : ($texts[Languages::SOURCE] ?? '')));
    }

    /**
     * The options of a field in a language, by position: option 2 in Dutch is
     * option 2 in English. A language without its own line falls back to the
     * source language.
     *
     * @return array<int, string>
     */
    public static function options(array $field, ?string $locale = null): array
    {
        $lines = fn (?string $text) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text)), fn ($line) => $line !== ''));
        $source = $lines($field['options'][Languages::SOURCE] ?? '');
        $own = $lines($field['options'][$locale ?? app()->getLocale()] ?? '');

        return array_map(fn (string $line, int $i) => $own[$i] ?? $line, $source, array_keys($source));
    }

    /** The addresses the submissions go to: the own list, otherwise the company address. */
    public function recipientList(): array
    {
        $list = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->recipients)),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));

        return $list ?: array_filter([Setting::get('company', 'email', config('mail.from.address'))]);
    }

    /**
     * Each own field gets a fixed key once, from its source label; answers and
     * merge fields refer to it, so renaming a field keeps them working.
     *
     * @param  array<int, mixed>  $fields
     * @return array<int, array<string, mixed>>
     */
    private static function withKeys(array $fields): array
    {
        $taken = array_filter(array_map(fn ($field) => is_array($field) ? ($field['key'] ?? null) : null, $fields));

        return array_values(array_map(function ($field) use (&$taken) {
            $field = is_array($field) ? $field : [];

            if (blank($field['key'] ?? null)) {
                $base = Str::slug(Str::limit(self::text($field, 'label', Languages::SOURCE), 30, ''), '_') ?: 'field';
                $key = $base;
                for ($i = 2; in_array($key, $taken, true) || in_array($key, ['name', 'email', 'phone', 'message', 'notes'], true); $i++) {
                    $key = "{$base}_{$i}";
                }
                $field['key'] = $taken[] = $key;
            }

            return $field;
        }, $fields));
    }
}
