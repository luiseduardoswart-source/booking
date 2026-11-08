<?php

namespace App\Support;

use App\Models\Form;
use Illuminate\Validation\Rule;

/**
 * The own fields of a form on the site: validation rules derived from the
 * fields, the answers as they are stored, and how an answer is shown again
 * (in the admin, a mail or a merge field) in a given language.
 *
 * An answer: key, type, label (source language at the time of sending),
 * value (text in the source language), and for choice fields the positions of
 * the chosen options, so they can be shown in another language.
 */
final class FormAnswers
{
    /** Inputs on the site are named answers[key]. */
    public const INPUT = 'answers';

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>} rules and the field names for the error messages
     */
    public static function rules(Form $form, ?string $locale = null): array
    {
        $rules = [];
        $names = [];

        foreach ($form->inputFields() as $field) {
            $path = self::INPUT.'.'.$field['key'];
            $required = (bool) ($field['required'] ?? false);
            $positions = array_keys(Form::options($field, Languages::SOURCE));
            $names[$path] = Form::text($field, 'label', $locale);

            $rules[$path] = match ($field['type']) {
                'checkbox' => $required ? ['accepted'] : ['nullable', 'boolean'],
                'checkboxes' => [$required ? 'required' : 'nullable', 'array'],
                'select', 'radio' => [$required ? 'required' : 'nullable', 'integer', Rule::in($positions)],
                'email' => [$required ? 'required' : 'nullable', 'email', 'max:255'],
                'number' => [$required ? 'required' : 'nullable', 'numeric', 'between:-1000000000,1000000000'],
                'date' => [$required ? 'required' : 'nullable', 'date'],
                'textarea' => [$required ? 'required' : 'nullable', 'string', 'max:5000'],
                default => [$required ? 'required' : 'nullable', 'string', 'max:1000'],
            };

            if ($field['type'] === 'checkboxes') {
                $rules[$path.'.*'] = ['integer', Rule::in($positions)];
                $names[$path.'.*'] = $names[$path];
            }
        }

        return [$rules, $names];
    }

    /**
     * The answers to the own fields from validated input.
     *
     * @param  array<string, mixed>  $input  the validated answers[...] list
     * @return array<int, array<string, mixed>>
     */
    public static function collect(Form $form, array $input): array
    {
        $answers = [];

        foreach ($form->inputFields() as $field) {
            $raw = $input[$field['key']] ?? null;
            $answer = ['key' => $field['key'], 'type' => $field['type'], 'label' => Form::text($field, 'label', Languages::SOURCE)];

            if (in_array($field['type'], Form::CHOICE_TYPES, true)) {
                $options = Form::options($field, Languages::SOURCE);
                $chosen = array_values(array_unique(array_map('intval', array_filter((array) $raw, fn ($value) => $value !== null && $value !== ''))));
                $answer['choices'] = $chosen;
                $answer['value'] = implode(', ', array_map(fn (int $i) => $options[$i] ?? '', $chosen));
            } elseif ($field['type'] === 'checkbox') {
                $answer['value'] = (bool) $raw;
            } else {
                $answer['value'] = trim((string) $raw);
            }

            $answers[] = $answer;
        }

        return $answers;
    }

    /** A fixed field (name, email...) as an answer; its label is a language file key. */
    public static function fixed(string $key, string $type, string $label, ?string $value): array
    {
        return ['key' => $key, 'type' => $type, 'label' => $label, 'value' => trim((string) $value), 'fixed' => true];
    }

    /** The label of an answer in a language: from the current field if it still exists. */
    public static function label(array $answer, ?Form $form = null, ?string $locale = null): string
    {
        if ($answer['fixed'] ?? false) {
            return __((string) $answer['label'], [], $locale);
        }

        $field = self::field($form, (string) ($answer['key'] ?? ''));

        return $field !== null && filled($text = Form::text($field, 'label', $locale ?? app()->getLocale()))
            ? $text
            : (string) ($answer['label'] ?? '');
    }

    /** The value of an answer as text in a language. */
    public static function value(array $answer, ?Form $form = null, ?string $locale = null): string
    {
        $type = $answer['type'] ?? 'text';

        if ($type === 'checkbox') {
            return ($answer['value'] ?? false) ? __('Yes', [], $locale) : __('No', [], $locale);
        }

        $field = self::field($form, (string) ($answer['key'] ?? ''));

        if ($field !== null && in_array($type, Form::CHOICE_TYPES, true) && isset($answer['choices'])) {
            $options = Form::options($field, $locale ?? app()->getLocale());

            return implode(', ', array_filter(array_map(fn ($i) => $options[(int) $i] ?? null, (array) $answer['choices'])));
        }

        return (string) ($answer['value'] ?? '');
    }

    private static function field(?Form $form, string $key): ?array
    {
        return collect($form?->ownFields() ?? [])->firstWhere('key', $key);
    }
}
