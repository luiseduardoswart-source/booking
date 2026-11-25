<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Support\Countries;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The guest registers every traveller, as the accommodation's country
 * requires (police or city registration). Through the signed link in the
 * confirmation and check-in emails and on My booking; it can be filled in
 * again until departure, which replaces what was there.
 */
class GuestRegistrationController extends Controller
{
    public function show(Booking $booking): View
    {
        abort_unless($this->open($booking), 404);

        $saved = $booking->guestRegistrations->keyBy('position');

        return view('site.bookings.guest-registration', [
            'booking' => $booking,
            'property' => $booking->property,
            'travellers' => collect(range(1, $booking->travellers()))->map(fn (int $position) => $saved[$position] ?? null),
            'countries' => Countries::options(),
        ]);
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($this->open($booking), 404);

        $count = $booking->travellers();
        $countries = array_keys(Countries::options());

        $validator = validator($request->all(), [
            'travellers' => ['required', 'array', "size:{$count}"],
            'travellers.*.first_name' => ['required', 'string', 'max:100'],
            'travellers.*.last_name' => ['required', 'string', 'max:150'],
            'travellers.*.sex' => ['required', Rule::in(array_keys(GuestRegistration::SEXES))],
            'travellers.*.birth_date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:today'],
            'travellers.*.birth_country' => ['nullable', Rule::in($countries)],
            'travellers.*.nationality' => ['required', Rule::in($countries)],
            'travellers.*.document_type' => ['nullable', Rule::in(array_keys(GuestRegistration::DOCUMENT_TYPES))],
            'travellers.*.document_number' => ['nullable', 'string', 'max:50'],
            'travellers.*.document_country' => ['nullable', Rule::in($countries)],
            // Where the main guest lives; the others may leave it empty.
            'travellers.1.residence_country' => ['required', Rule::in($countries)],
            'travellers.1.residence_city' => ['required', 'string', 'max:100'],
            'travellers.*.residence_country' => ['nullable', Rule::in($countries)],
            'travellers.*.residence_city' => ['nullable', 'string', 'max:100'],
            'travellers.*.residence_address' => ['nullable', 'string', 'max:255'],
        ], [], [
            // Readable names in the error messages instead of travellers.1.first_name.
            'travellers.*.first_name' => __('First name'),
            'travellers.*.last_name' => __('Last name(s)'),
            'travellers.*.sex' => __('Sex'),
            'travellers.*.birth_date' => __('Date of birth'),
            'travellers.*.birth_country' => __('Country of birth'),
            'travellers.*.nationality' => __('Nationality'),
            'travellers.*.document_type' => __('Type'),
            'travellers.*.document_number' => __('Document number'),
            'travellers.*.document_country' => __('Issued by'),
            'travellers.*.residence_country' => __('Country'),
            'travellers.*.residence_city' => __('City'),
            'travellers.*.residence_address' => __('Address'),
        ]);

        // From DOCUMENT_FROM_AGE on arrival, the identity document is required.
        $validator->after(function (Validator $validator) use ($booking): void {
            foreach ((array) ($validator->getData()['travellers'] ?? []) as $position => $traveller) {
                $birthDate = (string) ($traveller['birth_date'] ?? '');

                // A missing or wrong date already has its own error.
                if (! is_array($traveller) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) !== 1
                    || CarbonImmutable::parse($birthDate)->diffInYears($booking->arrival_date) < GuestRegistration::DOCUMENT_FROM_AGE) {
                    continue;
                }

                foreach (['document_type', 'document_number', 'document_country'] as $field) {
                    if (blank($traveller[$field] ?? null)) {
                        $validator->errors()->add("travellers.{$position}.{$field}", __('An identity document is required from the age of :age.', ['age' => GuestRegistration::DOCUMENT_FROM_AGE]));
                    }
                }
            }
        });

        $travellers = $validator->validate()['travellers'];

        DB::transaction(function () use ($booking, $travellers): void {
            $booking->guestRegistrations()->delete();

            foreach (array_values($travellers) as $index => $traveller) {
                $booking->guestRegistrations()->create([...$traveller, 'position' => $index + 1]);
            }
        });

        return redirect()->to($booking->signedLink('guest-registration.show'))->with('registration_saved', true);
    }

    /** Only while the accommodation asks for it and the stay is still ahead or going on. */
    private function open(Booking $booking): bool
    {
        return (bool) (int) $booking->property?->effective('guest_registration')
            && $booking->status->isConfirmed()
            && $booking->departure_date->greaterThanOrEqualTo(today());
    }
}
