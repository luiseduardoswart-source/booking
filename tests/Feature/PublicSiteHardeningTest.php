<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Support\EnvFile;
use App\Support\SiteLayouts;
use App\Support\SiteUrl;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The public site against abuse: limits on what costs work, no redirects
 * to other sites, links that cannot be bent to another domain, browser
 * security headers, and an installer that writes any password correctly.
 */
class PublicSiteHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(): Property
    {
        return Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000]);
    }

    public function test_bookings_from_one_visitor_are_limited(): void
    {
        $property = $this->accommodation();

        for ($i = 0; $i < 10; $i++) {
            $this->post("/{$property->slug}/book", [])->assertStatus(302);
        }

        $this->post("/{$property->slug}/book", [])->assertStatus(429);
    }

    public function test_photo_tracking_is_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->post('/track/photo', ['property_id' => 0, 'media_id' => 0]);
        }

        $this->post('/track/photo', ['property_id' => 0, 'media_id' => 0])->assertStatus(429);
    }

    public function test_the_calendar_api_computes_at_most_two_years(): void
    {
        $property = $this->accommodation();
        $property->update(['min_stay_nights' => 2]); // then every night is in the answer

        $nights = $this->getJson("/api/v1/properties/{$property->id}/unavailable-dates?from=0001-01-01&to=9999-12-31")
            ->assertOk()
            ->json('min_nights');

        // Not some three million nights: a year back and two years from there.
        $this->assertLessThanOrEqual(732, count($nights));
        $this->assertGreaterThan(700, count($nights));

        $this->getJson("/api/v1/properties/{$property->id}/unavailable-dates?from=yesterday-ish")->assertUnprocessable();
    }

    public function test_a_price_for_a_stay_of_years_is_not_computed(): void
    {
        $property = $this->accommodation();
        $arrival = today()->addDays(10);

        $this->getJson("/api/properties/{$property->id}/quote?".http_build_query([
            'arrival' => $arrival->toDateString(), 'departure' => $arrival->copy()->addDays(400)->toDateString(), 'guests' => 2,
        ]))->assertOk()->assertJsonPath('available', false);

        $this->getJson("/api/properties/{$property->id}/quote?".http_build_query([
            'arrival' => $arrival->toDateString(), 'departure' => '9999-12-31', 'guests' => 2,
        ]))->assertUnprocessable();
    }

    public function test_a_guest_name_is_one_line_of_text(): void
    {
        $property = $this->accommodation();
        $arrival = today()->addDays(10);

        $this->post("/{$property->slug}/book", [
            'arrival' => $arrival->toDateString(), 'departure' => $arrival->copy()->addDays(2)->toDateString(), 'guests' => 2,
            'name' => "Jan\n\nPlease pay to https://evil.example", 'email' => 'jan@example.com',
        ])->assertSessionHasErrors('name');

        $this->post('/contact', ['name' => "Jan\r\nBcc: x@example.com", 'email' => 'jan@example.com', 'message' => 'Hi'])
            ->assertSessionHasErrors('name');
    }

    public function test_switching_language_never_sends_you_to_another_site(): void
    {
        $this->withHeader('Referer', 'https://evil.example/phish')->get('/currency/EUR')->assertRedirect(route('home'));
        $this->withHeader('Referer', url('/contact'))->get('/currency/EUR')->assertRedirect(url('/contact'));
    }

    public function test_live_links_use_the_site_address_not_the_host_header(): void
    {
        config(['app.url' => 'https://booking.example']);
        $this->app['env'] = 'production';

        $this->app['request']->headers->set('Host', 'evil.example');
        SiteUrl::pinToAppUrl();

        $this->assertSame('https://booking.example/my-bookings', url('/my-bookings'));
    }

    public function test_pages_where_someone_is_logged_in_cannot_be_framed_by_another_site(): void
    {
        $this->get('/admin/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/my-bookings')->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        // An accommodation stays embeddable (the share modal offers an embed code).
        $this->get('/'.$this->accommodation()->slug)
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_the_installer_writes_any_password_so_it_reads_back_the_same(): void
    {
        $passwords = ['simple', 'with space', 'quote"inside', "single'quote", 'dollar$1and\\1', 'hash#tag', 'mix"\'$\\# all', "new\nline"];
        $values = [];

        foreach ($passwords as $i => $password) {
            $values["DB_PASSWORD_{$i}"] = $password;
        }

        $parsed = Dotenv::parse(EnvFile::set("APP_NAME=Booking\nDB_PASSWORD_0=old\n", $values));

        foreach ($passwords as $i => $password) {
            $this->assertSame(str_replace("\n", '', $password), $parsed["DB_PASSWORD_{$i}"], "password {$i}");
        }
        $this->assertSame('Booking', $parsed['APP_NAME']);
    }

    public function test_a_layout_cannot_put_php_in_the_public_folder(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'layout').'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('safetest/template.json', '{"name":"Safe test"}');
        $zip->addFromString('safetest/site/home.blade.php', 'home');
        $zip->addFromString('safetest/assets/shell.blade.php', '<?php system($_GET["c"]);');
        $zip->addFromString('safetest/assets/style.css', 'body{}');
        $zip->close();

        try {
            $name = SiteLayouts::installZip($zipPath);

            $this->assertFileExists(SiteLayouts::path($name).'/site/home.blade.php');
            $this->assertFileExists(SiteLayouts::assetsPath($name).'/style.css');
            $this->assertFileDoesNotExist(SiteLayouts::assetsPath($name).'/shell.blade.php');
        } finally {
            File::deleteDirectory(SiteLayouts::path('safetest'));
            File::deleteDirectory(SiteLayouts::assetsPath('safetest'));
        }
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('');
        parent::tearDown();
    }
}
