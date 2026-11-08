<?php

namespace Tests\Unit;

use App\Engines\BookingEngine;
use App\Engines\DocumentEngine;
use App\Engines\PaymentEngine;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Setting;
use App\Payments\FakeProvider;
use App\Payments\PaymentProvider;
use App\Payments\ProviderPaymentStatus;
use App\Support\DocumentText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DocumentEngineTest extends TestCase
{
    use RefreshDatabase;

    private DocumentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->engine = new DocumentEngine;
    }

    private function makeBooking(): Booking
    {
        $property = Property::create([
            'name' => 'Testhuis',
            'slug' => 'testhuis-'.uniqid(),
            'type' => 'holiday_home',
            'max_guests' => 4,
            'active' => true,
            'base_price_cents' => 10000,
        ]);

        return app(BookingEngine::class)->create(
            $property,
            CarbonImmutable::parse('2026-03-02'),
            CarbonImmutable::parse('2026-03-05'),
            2,
            ['name' => 'Jan Jansen', 'email' => 'jan@example.com'],
        );
    }

    public function test_invoice_generates_pdf_with_continuous_number(): void
    {

        $invoice1 = $this->engine->findOrCreateInvoice($this->makeBooking());
        $invoice2 = $this->engine->findOrCreateInvoice($this->makeBooking());

        $this->assertSame(sprintf('F-%d-0001', now()->year), $invoice1->invoice_number);
        $this->assertSame(sprintf('F-%d-0002', now()->year), $invoice2->invoice_number);

        $pdf = $this->engine->invoice($invoice1->booking);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_invoice_number_changes_never_at_again_generate(): void
    {
        $booking = $this->makeBooking();

        $first = $this->engine->findOrCreateInvoice($booking);
        $this->engine->invoice($booking);
        $second = $this->engine->findOrCreateInvoice($booking);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\Invoice::count());
    }

    public function test_vat_is_off_including_amount_calculated(): void
    {

        $invoice = $this->engine->findOrCreateInvoice($this->makeBooking());

        // €300 incl. 9% VAT → VAT component €300 × 9/109 = €24.77
        $this->assertSame(30000, $invoice->total_cents);
        $this->assertSame(2477, $invoice->vat_cents);
    }

    private function payDownPayment(Booking $booking): Payment
    {
        $provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $provider);
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        app(PaymentEngine::class)->syncFromProvider($payment);

        return $payment->refresh();
    }

    /** The text of a generated PDF, with a single space between everything. */
    private function text(string $pdf): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $pdf);

        try {
            return (string) preg_replace('/\s+/u', ' ', DocumentText::fromPath($path));
        } finally {
            unlink($path);
        }
    }

    public function test_confirmation_and_invoice_show_paid_and_still_to_pay(): void
    {
        $booking = $this->makeBooking(); // €300, down payment €90

        $this->assertStringContainsString('Nog te betalen € 300,00', $this->text($this->engine->confirmation($booking)));

        $this->payDownPayment($booking);
        $booking->refresh();

        foreach ([$this->engine->confirmation($booking), $this->engine->invoice($booking)] as $pdf) {
            $text = $this->text($pdf);
            $this->assertStringContainsString('Betaald € 90,00', $text);
            $this->assertStringContainsString('Nog te betalen € 210,00', $text);
        }
    }

    public function test_after_refund_and_cancel_is_there_nothing_more_open(): void
    {
        $booking = $this->makeBooking();
        $payment = $this->payDownPayment($booking); // sets up the fake provider first
        app(PaymentEngine::class)->refund($payment);
        app(BookingEngine::class)->cancel($booking->refresh());

        $text = $this->text($this->engine->confirmation($booking->refresh()));

        $this->assertStringContainsString('Terugbetaald - € 90,00', $text);
        $this->assertStringNotContainsString('Nog te betalen', $text);
    }

    public function test_confirmation_and_payment_receipt_generate_pdf(): void
    {
        $booking = $this->makeBooking();

        $this->assertStringStartsWith('%PDF', $this->engine->confirmation($booking));

        $provider = new FakeProvider;
        $this->app->instance(PaymentProvider::class, $provider);
        $payment = app(PaymentEngine::class)->createPayment($booking, PaymentType::DownPayment);
        $provider->setStatus($payment->provider_payment_id, ProviderPaymentStatus::Paid);
        app(PaymentEngine::class)->syncFromProvider($payment);

        $this->assertStringStartsWith('%PDF', $this->engine->receipt($payment->refresh()));
    }
}
