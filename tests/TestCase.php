<?php

namespace Tests;

use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Response of the fake leak check for passwords. Empty = nothing leaked.
     * A test that wants to simulate a leak or an outage sets a closure here
     * that returns an Http::response.
     */
    protected ?Closure $leakCheck = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Most tests make one accommodation and open its own page. On the site,
        // one accommodation is shown on the main domain by default and its own
        // address moves there (LocalizedUrl::homeProperty). The tests about
        // that switch it on themselves.
        if (in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            \App\Models\Setting::set('site', 'single_accommodation_direct', '0');
        }

        // Passwords are checked against Have I Been Pwned (see
        // AppServiceProvider). Tests never touch that real service.
        //
        // Via a property and not by calling Http::fake again in the test:
        // Laravel tries fake responses in order of registration, so this one
        // would always take precedence over a later one.
        Http::fake([
            'api.pwnedpasswords.com/*' => fn ($request) => $this->leakCheck
                ? ($this->leakCheck)($request)
                : Http::response('', 200),
        ]);

        // Addresses the server fetches are looked up in DNS before anything
        // is sent (OutboundUrl). Tests fake the answers and use made-up names
        // (airbnb.example): those point to a public address here, localhost
        // to the server itself. OutboundUrlTest also tests the real lookup.
        \App\Support\OutboundUrl::resolveUsing(fn (string $host): array => strtolower($host) === 'localhost' ? ['127.0.0.1'] : ['93.184.215.14']);
    }

    protected function tearDown(): void
    {
        \App\Support\OutboundUrl::resolveUsing(null);

        parent::tearDown();
    }
}
