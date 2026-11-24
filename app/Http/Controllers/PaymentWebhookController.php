<?php

namespace App\Http\Controllers;

use App\Engines\PaymentEngine;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentWebhookController extends Controller
{
    /** Calls per minute per sender (routes/web.php). */
    public const PER_MINUTE = 120;

    /**
     * Mollie only sends an id; we fetch the current status from the
     * provider ourselves (never trust the payload). Processing is idempotent.
     */
    public function mollie(Request $request, PaymentEngine $engine): Response
    {
        $providerPaymentId = $request->input('id');

        if (! is_string($providerPaymentId) || $providerPaymentId === '') {
            return response('Missing id', 400);
        }

        $payment = Payment::query()
            ->where('provider', 'mollie')
            ->where('provider_payment_id', $providerPaymentId)
            ->first();

        // Unknown payment: return 200 so Mollie stops retrying.
        if ($payment !== null) {
            $engine->syncFromProvider($payment);
        }

        return response('OK');
    }

    /**
     * Stripe sends JSON events. We never trust the payload: we only look up
     * our payment and fetch the status from Stripe ourselves.
     * With a configured webhook secret the signature is verified.
     */
    public function stripe(Request $request, PaymentEngine $engine): Response
    {
        $secret = \App\Models\Setting::get('payments', 'stripe_webhook_secret');

        if ($secret) {
            try {
                \Stripe\Webhook::constructEvent(
                    $request->getContent(),
                    $request->header('Stripe-Signature', ''),
                    $secret,
                );
            } catch (\Throwable) {
                return response('Invalid signature', 400);
            }
        }

        $objectId = $request->input('data.object.id');
        $metadataPaymentId = $request->input('data.object.metadata.payment_id');

        // Identity events (vs_...): update the booking via the verifier. If the
        // guest is verified by now but (e.g. by leaving the site) has not
        // been sent on to the payment, we still email the link.
        if (is_string($objectId) && str_starts_with($objectId, 'vs_')) {
            $booking = \App\Models\Booking::query()
                ->where('identity_session_id', $objectId)
                ->first();

            if ($booking !== null) {
                app(\App\Services\IdentityVerifier::class)->refresh($booking);
                $booking->refresh();

                $hasDownPaymentAlready = $booking->payments()
                    ->where('type', \App\Enums\PaymentType::DownPayment->value)
                    ->exists();

                if ($booking->identity_status === \App\Enums\IdentityStatus::Verified && ! $hasDownPaymentAlready) {
                    try {
                        $payment = $engine->createPayment($booking, \App\Enums\PaymentType::DownPayment);
                        app(\App\Engines\NotificationEngine::class)->send($booking, \App\Enums\MailType::Verified, $payment);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }

            return response('OK');
        }

        $payment = Payment::query()
            ->where('provider', 'stripe')
            ->where(function ($query) use ($objectId, $metadataPaymentId) {
                $query->when($objectId, fn ($q) => $q->orWhere('provider_payment_id', $objectId))
                    ->when($metadataPaymentId, fn ($q) => $q->orWhere('id', $metadataPaymentId));
            })
            ->first();

        // A refund or a dispute (the guest's bank reversing the payment) names
        // the Stripe payment (pi_...), not our checkout: look the checkout up.
        // Only for a webhook that proved it comes from Stripe (secret set), so
        // nobody can make us question Stripe at will.
        $paymentIntent = $request->input('data.object.payment_intent');
        $provider = app(\App\Payments\PaymentProvider::class);

        if ($payment === null && $secret && is_string($paymentIntent) && $provider instanceof \App\Payments\StripeProvider) {
            try {
                $sessionId = $provider->sessionIdFor($paymentIntent);
                $payment = $sessionId === null ? null : Payment::query()
                    ->where('provider', 'stripe')
                    ->where('provider_payment_id', $sessionId)
                    ->first();
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($payment !== null) {
            $engine->syncFromProvider($payment);
        }

        return response('OK');
    }
}
