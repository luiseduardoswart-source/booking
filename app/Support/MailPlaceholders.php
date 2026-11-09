<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Form;
use App\Models\Property;
use App\Models\Setting;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Merge fields in the custom texts of the automatic mails: in the editor you
 * type {{ and pick, for example, "Guest name"; in the mail the real name
 * appears there. One list for the editor and for the mail.
 */
final class MailPlaceholders
{
    /** Merge field key of an own check-in field: this prefix plus the field's fixed key. */
    public const FIELD_PREFIX = 'field_';

    /** Merge field key of an own field of the booking form: this prefix plus the field's key. */
    public const ANSWER_PREFIX = 'answer_';

    /**
     * Key (stored in the saved text, do not change) => label in the editor,
     * in the admin's language.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $ownFields = collect(Property::checkInFieldNames())
            ->mapWithKeys(fn (string $name, string $key) => [self::FIELD_PREFIX.$key => $name])
            ->all();

        $answers = collect(Form::fixed(Form::BOOKING)->inputFields())
            ->mapWithKeys(fn (array $field) => [self::ANSWER_PREFIX.$field['key'] => Form::text($field, 'label')])
            ->all();

        return [
            'name' => __('Guest name'),
            'first_name' => __('Guest first name'),
            'accommodation' => __('Accommodation name'),
            'booking_number' => __('Booking number'),
            'arrival' => __('Arrival date'),
            'departure' => __('Departure date'),
            'nights' => __('Number of nights'),
            'guests' => __('Number of guests'),
            'check_in' => __('Check-in from'),
            'check_out' => __('Check-out (time)'),
            'access_code' => __('Access code'),
            'address' => __('Accommodation address'),
            'total' => __('Total amount'),
            'company' => __('Company name'),
            'phone' => __('Company phone number'),
            'email' => __('Company email address'),
            ...$ownFields,
            ...$answers,
        ];
    }

    /**
     * The text for the mail. From the editor (HTML with merge fields): the
     * fields filled in, the values as plain text. An older text without
     * formatting stays as it was.
     */
    public static function fillIn(string $text, Booking $booking): string|HtmlString
    {
        if ($text === strip_tags($text)) {
            return $text;
        }

        $html = RichContentRenderer::make($text)
            ->mergeTags(self::values($booking))
            ->toHtml();

        // On one line: a blank line would break the HTML block in the Markdown
        // mail, after which the rest is read as Markdown.
        return new HtmlString(preg_replace('/\s*\n\s*/', ' ', $html));
    }

    /** @return array<string, callable(): string> */
    private static function values(Booking $booking): array
    {
        $property = $booking->property;
        $language = app()->getLocale();
        $date = fn ($day) => $day === null ? '' : Carbon::parse($day)->locale($language)->translatedFormat('l j F Y');
        $time = fn (string $field) => ($value = $property?->effective($field)) ? Carbon::parse($value)->format('H:i') : '';

        $ownFields = collect(Property::checkInFieldNames())
            ->mapWithKeys(fn (string $name, string $key) => [self::FIELD_PREFIX.$key => fn () => (string) $property?->checkInValue($key)])
            ->all();

        // The guest's answers to the own fields of the booking form, in the mail's language.
        $bookingForm = Form::fixed(Form::BOOKING);
        $answers = collect($bookingForm->inputFields())
            ->mapWithKeys(fn (array $field) => [self::ANSWER_PREFIX.$field['key'] => function () use ($booking, $bookingForm, $field) {
                $answer = collect((array) $booking->form_answers)->firstWhere('key', $field['key']);

                return $answer === null ? '' : FormAnswers::value($answer, $bookingForm);
            }])
            ->all();

        // Only calculated if the field is in the text.
        return [
            'name' => fn () => (string) $booking->guest_name,
            'first_name' => fn () => (string) strtok(trim((string) $booking->guest_name), ' '),
            'accommodation' => fn () => (string) $property?->localized('name'),
            'booking_number' => fn () => (string) $booking->booking_number,
            'arrival' => fn () => $date($booking->arrival_date),
            'departure' => fn () => $date($booking->departure_date),
            'nights' => fn () => (string) $booking->nights(),
            'guests' => fn () => (string) $booking->guests,
            'check_in' => fn () => $time('check_in_from'),
            'check_out' => fn () => $time('check_out_until'),
            // No code to give (not confirmed, no phone number...): never an empty spot in the mail.
            'access_code' => fn () => $property?->accessCodeFor($booking) ?? __('You will receive the code from us separately.'),
            'address' => fn () => trim(implode(', ', array_filter([
                $property?->address,
                trim($property?->postal_code.' '.$property?->city),
            ]))),
            'total' => fn () => Money::format((int) $booking->total_cents, $booking->currency),
            'company' => fn () => (string) Setting::get('company', 'name', config('app.name')),
            'phone' => fn () => (string) Setting::get('company', 'phone', ''),
            'email' => fn () => (string) Setting::get('company', 'email', ''),
            ...$ownFields,
            ...$answers,
        ];
    }
}
