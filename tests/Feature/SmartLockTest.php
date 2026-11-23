<?php

namespace Tests\Feature;

use App\Engines\BookingEngine;
use App\Engines\PaymentEngine;
use App\Enums\AdminNotification;
use App\Enums\BookingStatus;
use App\Enums\MailType;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Mail\AdminNotificationMail;
use App\Mail\BookingMail;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Self check-in with a smart lock through Seam: every stay paid in full gets
 * a code of its own, valid from check-in to check-out in the accommodation's
 * time zone, sent in the check-in mail. A cancelled stay loses it again.
 */
class SmartLockTest extends TestCase
{
    use RefreshDatabase;

    private const SEAM = 'https://connect.getseam.com';

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo('2027-03-01 08:00:00');
        Setting::set('locks', 'seam_api_key', 'seam_test_key');

        $this->property = Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true,
            'base_price_cents' => 10000, 'checkin_days_before_arrival' => 3,
            'check_in_from' => '15:00', 'check_out_until' => '11:00', 'time_zone' => 'America/Bogota',
            'check_in_kind' => Property::CHECK_IN_SELF, 'access_code_kind' => Property::ACCESS_SMART_LOCK, 'smart_lock_device_id' => 'dev-1',
            'mail_texts' => ['checkin' => ['en' => '<p>Your code: <span data-type="mergeTag" data-id="access_code"></span>.</p>']],
        ]);
    }

    /** Arrival the day after tomorrow, confirmed by the owner (paid outside the site). */
    private function booking(): Booking
    {
        $booking = app(BookingEngine::class)->create(
            $this->property, CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-06'), 2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
        app(BookingEngine::class)->transition($booking, BookingStatus::Confirmed);
        $booking->update(['locale' => 'en']); // the language of the mail text above

        return $booking->refresh();
    }

    /**
     * Seam's answers. Unless given, the lock has no codes yet.
     *
     * @param  array<string, mixed>  $responses  path => response (or sequence)
     */
    private function seam(array $responses): void
    {
        $responses += ['/access_codes/list' => Http::response(['access_codes' => [], 'pagination' => []])];

        Http::fake(collect($responses)->mapWithKeys(fn ($response, string $path) => [self::SEAM.$path.'*' => $response])->all());
    }

    private function told(AdminNotification $notification): int
    {
        return Mail::sent(AdminNotificationMail::class, fn (AdminNotificationMail $mail) => $mail->notification === $notification)->count();
    }

    private function made(?string $code = '4821'): array
    {
        return ['access_code' => ['access_code_id' => 'ac_1', 'code' => $code, 'status' => 'setting']];
    }

    /** @return list<BookingMail> */
    private function checkInMails(Booking $booking): array
    {
        $this->artisan('mails:send-scheduled')->assertSuccessful();

        return Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Checkin && $mail->booking->is($booking))->all();
    }

    private function text(BookingMail $mail): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($mail->render()), ENT_QUOTES));
    }

    private function sent(string $path): int
    {
        return Http::recorded(fn (Request $request) => str_starts_with($request->url(), self::SEAM.$path))->count();
    }

    public function test_a_paid_stay_gets_a_code_of_its_own_that_only_opens_during_the_stay(): void
    {
        $this->seam(['/access_codes/create' => Http::response($this->made())]);
        $booking = $this->booking();

        $mails = $this->checkInMails($booking);

        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Your code: 4821.', $this->text($mails[0]));
        Http::assertSent(fn (Request $request) => $request->url() === self::SEAM.'/access_codes/create'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer seam_test_key')
            && $request['device_id'] === 'dev-1'
            && $request['name'] === $booking->booking_number
            // 15:00 and 11:00 in Cartagena, not in UTC.
            && $request['starts_at'] === '2027-03-03T15:00:00-05:00'
            && $request['ends_at'] === '2027-03-06T11:00:00-05:00');

        // Once: the next run asks the lock nothing more.
        $this->checkInMails($booking);
        $this->assertSame(1, $this->sent('/access_codes/create'));

        // The code is stored encrypted and never leaves the model by accident.
        $this->assertSame('4821', $this->property->accessCodeFor($booking->refresh()));
        $this->assertStringNotContainsString('4821', (string) DB::table('bookings')->where('id', $booking->id)->value('lock_code'));
        $this->assertArrayNotHasKey('lock_code', $booking->toArray());
    }

    public function test_a_stay_with_only_the_down_payment_paid_does_not_get_a_code(): void
    {
        $this->seam(['/access_codes/create' => Http::response($this->made())]);
        $provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $provider);
        $this->property->update(['down_payment_percentage' => 30]);

        $booking = app(BookingEngine::class)->create(
            $this->property, CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-06'), 2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        app(PaymentEngine::class)->syncFromProvider($payment);

        $this->assertTrue($booking->refresh()->owesBalance());
        $this->assertCount(0, $this->checkInMails($booking));
        Http::assertNothingSent();
    }

    public function test_digits_the_lock_chooses_later_are_fetched_before_the_mail_goes(): void
    {
        $this->seam([
            '/access_codes/create' => Http::response($this->made(null)),
            '/access_codes/get' => Http::sequence()
                ->push(['access_code' => ['access_code_id' => 'ac_1', 'code' => null]])
                ->push(['access_code' => ['access_code_id' => 'ac_1', 'code' => '7302']]),
        ]);
        $booking = $this->booking();

        // The lock has not set the digits yet: the mail waits for the next run.
        $this->assertCount(0, $this->checkInMails($booking));
        $this->assertSame('ac_1', $booking->refresh()->lock_code_id);

        $this->travelTo('2027-03-02 08:00:00');
        $mails = $this->checkInMails($booking);

        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Your code: 7302.', $this->text($mails[0]));
        $this->assertSame(1, $this->sent('/access_codes/create'));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::SEAM.'/access_codes/get?access_code_id=ac_1');
    }

    public function test_when_the_lock_gives_no_code_the_mail_waits_until_the_day_of_arrival_and_the_owner_hears_it(): void
    {
        $offline = ['error' => ['type' => 'device_offline', 'message' => 'Offline']];
        $this->seam(['/access_codes/create' => Http::sequence()->push($offline, 400)->push($offline, 400)->push($this->made('5190'))]);
        $booking = $this->booking();

        $this->assertCount(0, $this->checkInMails($booking));
        $this->assertSame(0, $this->told(AdminNotification::SmartLockFailed));

        // The day of arrival: the guest gets the mail anyway, the owner sends the code.
        $this->travelTo('2027-03-03 08:00:00');
        $mails = $this->checkInMails($booking);

        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Your code: '.__('You will receive the code from us separately.', [], 'en'), $this->text($mails[0]));
        $this->assertSame(1, $this->told(AdminNotification::SmartLockFailed));

        // The lock answers again: the guest gets the check-in mail once more, now with the code.
        $this->artisan('locks:sync')->assertSuccessful();

        $mails = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Checkin)->all();
        $this->assertCount(2, $mails);
        $this->assertStringContainsString('Your code: 5190.', $this->text($mails[1]));
    }

    public function test_a_mail_that_waits_for_the_lock_goes_within_the_hour_once_the_lock_answers(): void
    {
        $this->seam(['/access_codes/create' => Http::sequence()->push(['error' => ['type' => 'provider_issue', 'message' => 'Down']], 503)->push($this->made())]);
        $booking = $this->booking();
        $this->assertCount(0, $this->checkInMails($booking));

        // Not tomorrow at 09:00: the hourly run sends it as soon as the lock answers.
        $this->artisan('locks:sync')->assertSuccessful();
        $this->artisan('locks:sync')->assertSuccessful();

        $mails = Mail::queued(BookingMail::class, fn (BookingMail $mail) => $mail->type === MailType::Checkin)->all();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Your code: 4821.', $this->text($mails[0]));
    }

    public function test_a_stay_whose_check_in_mail_is_not_due_yet_gets_no_code_from_the_hourly_run(): void
    {
        $this->seam(['/access_codes/create' => Http::response($this->made())]);
        $booking = $this->booking();
        $booking->update(['arrival_date' => '2027-03-20', 'departure_date' => '2027-03-23']);

        $this->artisan('locks:sync')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($booking->refresh()->lock_code_id);
    }

    public function test_a_code_whose_answer_got_lost_is_taken_over_instead_of_making_a_second_one(): void
    {
        $booking = $this->booking();
        $this->seam(['/access_codes/list' => Http::response(['access_codes' => [
            ['access_code_id' => 'ac_other', 'name' => 'BK-OTHER', 'code' => '1111'],
            ['access_code_id' => 'ac_lost', 'name' => $booking->booking_number, 'code' => '6604'],
        ], 'pagination' => []])]);

        $mails = $this->checkInMails($booking);

        $this->assertStringContainsString('Your code: 6604.', $this->text($mails[0]));
        $this->assertSame('ac_lost', $booking->refresh()->lock_code_id);
        $this->assertSame(0, $this->sent('/access_codes/create'));
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === self::SEAM.'/access_codes/list?device_id=dev-1');
    }

    public function test_smart_lock_as_the_default_without_a_lock_picked_tells_the_owner_at_once(): void
    {
        $this->seam([]);
        Property::master()->update(['check_in_kind' => Property::CHECK_IN_SELF, 'access_code_kind' => Property::ACCESS_SMART_LOCK]);
        $this->property->update(['access_code_kind' => null, 'smart_lock_device_id' => null]);
        $booking = $this->booking();

        // Waiting does not help: the mail goes, the owner hears it now, not on the day of arrival.
        $mails = $this->checkInMails($booking);

        $this->assertCount(1, $mails);
        $this->assertStringContainsString('Your code: '.__('You will receive the code from us separately.', [], 'en'), $this->text($mails[0]));
        $this->assertSame(1, $this->told(AdminNotification::SmartLockFailed));
        Http::assertNothingSent();
    }

    public function test_a_cancelled_stay_loses_its_code(): void
    {
        $this->seam([
            '/access_codes/create' => Http::response($this->made()),
            '/access_codes/delete' => Http::response([]),
        ]);
        $booking = $this->booking();
        $this->checkInMails($booking);

        app(BookingEngine::class)->transition($booking->refresh(), BookingStatus::Cancelled);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::SEAM.'/access_codes/delete?access_code_id=ac_1');
        $booking->refresh();
        $this->assertNull($booking->lock_code_id);
        $this->assertNull($booking->lock_code);
    }

    public function test_a_removal_that_failed_is_told_once_and_tried_again_until_the_code_is_off_the_lock(): void
    {
        $down = ['error' => ['type' => 'provider_issue', 'message' => 'Down']];
        $this->seam([
            '/access_codes/create' => Http::response($this->made()),
            '/access_codes/delete' => Http::sequence()->push($down, 503)->push($down, 503)->push([]),
        ]);
        $booking = $this->booking();
        $this->checkInMails($booking);

        app(BookingEngine::class)->transition($booking->refresh(), BookingStatus::Cancelled);
        $this->assertSame('ac_1', $booking->refresh()->lock_code_id);
        $this->assertSame(1, $this->told(AdminNotification::SmartLockNotRemoved));

        $this->artisan('locks:sync')->assertSuccessful();
        $this->assertSame('ac_1', $booking->refresh()->lock_code_id);

        $this->artisan('locks:sync')->assertSuccessful();

        $this->assertNull($booking->refresh()->lock_code_id);
        $this->assertSame(3, $this->sent('/access_codes/delete'));
        // Told once, not at every try.
        $this->assertSame(1, $this->told(AdminNotification::SmartLockNotRemoved));
    }

    public function test_a_payment_given_back_takes_the_code_off_the_lock(): void
    {
        $this->seam([
            '/access_codes/create' => Http::response($this->made()),
            '/access_codes/delete' => Http::response([]),
        ]);
        $provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $provider);
        $this->property->update(['down_payment_percentage' => Property::FULL_PAYMENT]);

        // Paid in full on the site.
        $booking = app(BookingEngine::class)->create(
            $this->property, CarbonImmutable::parse('2027-03-03'), CarbonImmutable::parse('2027-03-06'), 2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        app(PaymentEngine::class)->syncFromProvider($payment);
        $this->checkInMails($booking->refresh());
        $this->assertSame('4821', $this->property->accessCodeFor($booking->refresh()));

        // The money goes back: the door closes again.
        app(PaymentEngine::class)->refund($payment->refresh());
        $this->artisan('locks:sync')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === self::SEAM.'/access_codes/delete?access_code_id=ac_1');
        $this->assertNull($booking->refresh()->lock_code_id);
        $this->assertNull($this->property->accessCodeFor($booking));
    }

    public function test_the_owner_picks_the_lock_and_the_time_zone_in_the_admin(): void
    {
        $this->seam(['/devices/list' => Http::response(['devices' => [
            ['device_id' => 'dev-1', 'display_name' => 'Front door'],
            ['device_id' => 'dev-2', 'display_name' => 'Garden gate'],
        ], 'pagination' => []])]);
        $owner = User::factory()->create(['role' => UserRole::Administrator]);
        $accommodation = Property::create([
            'name' => 'Duinhuis', 'slug' => 'duinhuis', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
        ]);

        $form = Livewire::actingAs($owner)
            ->test(EditProperty::class, ['record' => $accommodation->getKey()])
            ->fillForm(['check_in_kind' => Property::CHECK_IN_SELF])
            ->fillForm(['access_code_kind' => Property::ACCESS_SMART_LOCK])
            ->assertSee('Garden gate')
            // The front door is the beach house's lock: one lock opens one door.
            ->assertDontSee('Front door');

        $form->fillForm(['smart_lock_device_id' => 'dev-1', 'time_zone' => 'Europe/Amsterdam'])
            ->call('save')
            ->assertHasFormErrors(['smart_lock_device_id']);

        $form->fillForm(['smart_lock_device_id' => 'dev-2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $accommodation->refresh();
        $this->assertSame('dev-2', $accommodation->smartLockDevice());
        $this->assertSame('Europe/Amsterdam', $accommodation->timeZone());
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_starts_with($request->url(), self::SEAM.'/devices/list'));
    }

    public function test_the_accommodation_can_be_saved_while_seam_does_not_answer(): void
    {
        $this->seam(['/devices/list' => Http::response(['error' => ['type' => 'provider_issue', 'message' => 'Down']], 503)]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(EditProperty::class, ['record' => $this->property->getKey()])
            ->fillForm(['name' => 'Strandhuis aan zee'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->property->refresh();
        $this->assertSame('Strandhuis aan zee', $this->property->name);
        $this->assertSame('dev-1', $this->property->smartLockDevice());
    }

    public function test_the_seam_key_is_saved_encrypted_without_the_spaces_it_was_pasted_with(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Administrator]))
            ->test(Settings::class)
            ->fillForm(['company_name' => 'Beach BV', 'locks_seam_api_key' => " seam_live_pasted\n"])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('seam_live_pasted', Setting::get('locks', 'seam_api_key'));
        $this->assertStringNotContainsString('seam_live_pasted', (string) DB::table('settings')->where('group', 'locks')->value('value'));
    }
}
