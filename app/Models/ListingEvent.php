<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Simple, privacy-friendly event log for the performance charts:
 * page views, photo views, price requests and bookings. First-party,
 * no extra cookies (uses the existing session), and tracking must never
 * break a page view.
 */
class ListingEvent extends Model
{
    protected $guarded = [];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function record(string $type, ?Property $property = null, array $extra = []): void
    {
        try {
            $request = request();

            static::create(array_merge([
                'type' => $type,
                'property_id' => $property?->getKey(),
                'session_id' => $request?->hasSession() ? $request->session()->getId() : null,
                'locale' => app()->getLocale(),
                'device' => static::detectDevice((string) ($request?->userAgent() ?? '')),
                'referrer_host' => static::externalReferrerHost($request),
            ], $extra));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected static function detectDevice(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }

        if (preg_match('/iPad|Tablet|PlayBook|Silk|Android(?!.*Mobile)/i', $ua)) {
            return 'tablet';
        }

        if (preg_match('/Mobile|iPhone|iPod|Windows Phone|Android.*Mobile/i', $ua)) {
            return 'mobile';
        }

        return 'desktop';
    }

    protected static function externalReferrerHost(?Request $request): ?string
    {
        $referer = $request?->headers->get('referer');

        if (! $referer) {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        // Referrals from the site itself do not count as an external origin.
        if ($request && $host === $request->getHost()) {
            return null;
        }

        return Str::of($host)->lower()->replaceMatches('/^www\./', '')->toString();
    }
}
