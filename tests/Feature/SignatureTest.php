<?php

namespace Tests\Feature;

use App\Engines\DocumentEngine;
use App\Models\Booking;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesSignature;
use Tests\TestCase;

/**
 * Digital signature under the rental terms, when booking.
 */
class SignatureTest extends TestCase
{
    use MakesSignature, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** Rental terms apply from 7 nights. */
    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis', 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000,
            'rental_terms' => 'Borg wordt ingehouden bij schade.',
            'rental_terms_min_nights' => 7,
        ]);
    }

    private function book(string $departure, ?string $signature)
    {
        return $this->post('/strandhuis/book', array_filter([
            'arrival' => '2026-12-01',
            'departure' => $departure,
            'guests' => 2,
            'name' => 'Jan Jansen',
            'email' => 'jan@example.com',
            'terms' => '1',
            'signature' => $signature,
        ], fn ($value) => $value !== null));
    }

    private const LANG = '2026-12-09'; // 8 nights: rental terms apply

    private const SHORT = '2026-12-04'; // 3 nights: they do not

    public function test_without_signature_no_booking(): void
    {
        $this->accommodation();

        $this->book(self::LANG, null)->assertSessionHasErrors('signature');

        $this->assertSame(0, Booking::count());
    }

    public function test_with_signature_is_everything_recorded(): void
    {
        $this->accommodation();

        $this->book(self::LANG, $this->signature())->assertSessionHasNoErrors();

        $booking = Booking::firstOrFail();
        $this->assertSame('Jan Jansen', $booking->signature_name);
        $this->assertNotNull($booking->signed_at);
        $this->assertNotNull($booking->signature_ip);
        $this->assertSame(hash('sha256', $booking->terms_snapshot), $booking->signature_hash);
        $this->assertTrue($booking->signedTextUnchanged());
        Storage::disk('local')->assertExists($booking->signature_path);
    }

    public function test_the_signature_is_not_in_the_public_storage(): void
    {
        $this->accommodation();
        $this->book(self::LANG, $this->signature());

        $path = Booking::firstOrFail()->signature_path;

        Storage::disk('public')->assertMissing($path);
        $this->assertStringStartsWith('signatures/', $path);
    }

    public function test_at_a_short_stay_is_no_signature_needed(): void
    {
        $this->accommodation();

        $this->book(self::SHORT, null)->assertSessionHasNoErrors();

        $booking = Booking::firstOrFail();
        $this->assertNull($booking->signed_at);
        $this->assertNull($booking->signature_path);
    }

    public function test_a_empty_drawing_box_is_rejected(): void
    {
        $this->accommodation();

        $this->book(self::LANG, $this->emptySignatureBox())->assertSessionHasErrors('signature');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_white_picture_counts_also_as_empty(): void
    {
        $this->accommodation();

        $this->book(self::LANG, $this->whiteBox())->assertSessionHasErrors('signature');
    }

    public function test_only_a_png_is_accepted(): void
    {
        $this->accommodation();
        $jpeg = 'data:image/jpeg;base64,'.base64_encode('geen echte afbeelding');

        $this->book(self::LANG, $jpeg)->assertSessionHasErrors('signature');
        $this->book(self::LANG, 'data:image/png;base64,!!!no-base64!!!')->assertSessionHasErrors('signature');
    }

    public function test_a_to_large_picture_is_rejected(): void
    {
        $this->accommodation();

        $this->book(self::LANG, $this->signature(3000, 160))->assertSessionHasErrors('signature');
    }

    public function test_a_afterwards_changed_text_is_recognized(): void
    {
        $this->accommodation();
        $this->book(self::LANG, $this->signature());

        $booking = Booking::firstOrFail();
        $booking->forceFill(['terms_snapshot' => $booking->terms_snapshot.' Extra regel.'])->save();

        $this->assertFalse($booking->fresh()->signedTextUnchanged());
    }

    public function test_signature_fields_are_not_via_a_form_to_set(): void
    {
        $booking = new Booking(['signature_name' => 'Vervalst', 'signature_hash' => str_repeat('0', 64)]);

        $this->assertNull($booking->signature_name);
        $this->assertNull($booking->signature_hash);
    }

    public function test_the_confirmation_pdf_shows_the_signature(): void
    {
        $this->accommodation();
        $this->book(self::LANG, $this->signature());
        $booking = Booking::firstOrFail();

        $company = array_fill_keys(['name', 'address', 'postal_code_city', 'email', 'phone', 'coc', 'vat_number', 'iban'], null);
        $html = view('pdf.confirmation', ['booking' => $booking, 'company' => $company])->render();

        // And the real PDF is generated without errors, with the image in it.
        $this->assertStringStartsWith('%PDF', app(DocumentEngine::class)->confirmation($booking));

        $this->assertStringContainsString(__('Digitally signed'), $html);
        $this->assertStringContainsString('Jan Jansen', $html);
        $this->assertStringContainsString($booking->signature_hash, $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString(__('The text above has not changed since it was signed.'), $html);
    }

    public function test_the_booking_form_shows_the_drawing_box_only_as_the_needed_is(): void
    {
        $this->accommodation();

        $this->get('/strandhuis/book?arrival=2026-12-01&departure='.self::LANG.'&guests=2')
            ->assertOk()
            ->assertSee('data-signature', false);

        $this->get('/strandhuis/book?arrival=2026-12-01&departure='.self::SHORT.'&guests=2')
            ->assertOk()
            ->assertDontSee('data-signature', false);
    }
}
