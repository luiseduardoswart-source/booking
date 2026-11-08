<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalHostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The middleware deliberately only acts when live, so for this test we
     * pretend. Restore afterwards, otherwise it leaks into other tests.
     */
    private function asIfLive(string $appUrl, callable $test): void
    {
        $old = app()->environment();
        app()->instance('env', 'production');
        config(['app.url' => $appUrl]);

        try {
            $test();
        } finally {
            app()->instance('env', $old);
        }
    }

    public function test_visitor_on_the_wrong_host_name_is_permanent_redirected(): void
    {
        $this->asIfLive('https://www.example.nl', function () {
            $this->get('http://example.nl/accommodations?guests=2')
                ->assertStatus(301)
                ->assertRedirect('https://www.example.nl/accommodations?guests=2');
        });
    }

    public function test_the_host_name_off_app_url_is_with_rest_left(): void
    {
        $this->asIfLive('https://www.example.nl', function () {
            $this->get('https://www.example.nl/')->assertOk();
        });
    }

    public function test_also_reverse_of_www_to_the_bare_domain(): void
    {
        $this->asIfLive('https://example.nl', function () {
            $this->get('https://www.example.nl/')
                ->assertRedirect('https://example.nl/');
        });
    }

    public function test_webhooks_are_not_redirected_because_then_go_the_details_lost(): void
    {
        $this->asIfLive('https://www.example.nl', function () {
            $answer = $this->post('http://example.nl/webhooks/mollie', ['id' => 'tr_test']);

            $this->assertNotSame(301, $answer->getStatusCode());
        });
    }

    public function test_without_production_happens_there_nothing(): void
    {
        config(['app.url' => 'https://www.example.nl']);

        $this->get('http://example.nl/')->assertOk();
    }
}
