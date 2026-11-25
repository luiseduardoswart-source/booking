<?php

namespace Tests\Feature;

use App\Enums\MailType;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Support\MailPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A guest who stops at the payment: when the booking gives its dates back
 * after the hour, one friendly email with a link to book the same dates
 * again, while they are still free.
 */
class UnfinishedBookingMailTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(PaymentProvider::class, new FakeProvider);
        Setting::set('payments', 'provider', 'fake');

        $this->property = Property::create([
            'name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);
    }

    private function bookOnTheSite(string $email = 'jan@example.com', int $daysAhead = 40): Booking
    {
        $this->post('/beach-house/book', [
            'arrival' => today()->addDays($daysAhead)->toDateString(),
            'departure' => today()->addDays($daysAhead + 3)->toDateString(),
            'guests' => 2, 'name' => 'Jan Jansen', 'email' => $email, 'terms' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Booking::query()->latest('id')->first();
    }

    private function releaseAfterTheHour(): void
    {
        $this->travel(Booking::HOLD_MINUTES + 1)->minutes();
        $this->artisan('bookings:release-unpaid')->assertSuccessful();
    }

    /** @return \Illuminate\Support\Collection<int, BookingMail> */
    private function reminders(): \Illuminate\Support\Collection
    {
        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::UnfinishedBooking);
    }

    public function test_the_guest_gets_one_email_to_book_the_same_dates_again(): void
    {
        $booking = $this->bookOnTheSite();

        $this->releaseAfterTheHour();
        $this->assertCount(1, $this->reminders());

        $mail = $this->reminders()->sole();
        $this->assertTrue($mail->booking->is($booking));
        $this->assertTrue($mail->hasTo('jan@example.com'));
        $html = $mail->render();
        $link = e($booking->rebookUrl());
        $this->assertStringContainsString($link, $html);
        $this->assertStringContainsString('arrival='.$booking->arrival_date->toDateString(), $html);

        // The link opens the accommodation page with those dates.
        $this->get($booking->rebookUrl())->assertOk();

        // Never a second time.
        $this->artisan('bookings:release-unpaid')->assertSuccessful();
        $this->assertCount(1, $this->reminders());
    }

    public function test_no_email_when_the_dates_were_taken_in_the_meantime(): void
    {
        $booking = $this->bookOnTheSite();
        // Within the hour a booking for those dates came in from Airbnb.
        \App\Models\CalendarBlock::create([
            'property_id' => $this->property->id, 'type' => \App\Enums\CalendarBlockType::External,
            'start_date' => $booking->arrival_date->toDateString(), 'end_date' => $booking->departure_date->subDay()->toDateString(),
            'source' => 'airbnb-1', 'external_uid' => 'abc@airbnb.com', 'note' => 'Reserved',
        ]);

        $this->releaseAfterTheHour();

        $this->assertCount(0, $this->reminders());
    }

    public function test_no_email_for_an_arrival_that_has_passed(): void
    {
        $booking = $this->bookOnTheSite();
        $booking->update(['arrival_date' => today()->subDay()->toDateString(), 'departure_date' => today()->addDays(2)->toDateString()]);

        $this->releaseAfterTheHour();

        $this->assertCount(0, $this->reminders());
    }

    public function test_the_owner_can_switch_it_off_per_accommodation(): void
    {
        $this->property->update(['unfinished_booking_mail' => false]);
        $this->bookOnTheSite();

        $this->releaseAfterTheHour();

        $this->assertCount(0, $this->reminders());
        $this->assertTrue((bool) Property::master()->unfinished_booking_mail);
    }

    public function test_the_standard_email_can_be_previewed_with_the_own_text(): void
    {
        $html = MailPreview::html($this->property, MailType::UnfinishedBooking, 'en');

        $this->assertStringContainsString(__('Book these dates again', [], 'en'), $html);
        $this->assertSame(1, substr_count($html, __('Your own text appears here', [], 'en')));
    }
}
