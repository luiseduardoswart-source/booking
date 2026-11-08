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
    }
}
