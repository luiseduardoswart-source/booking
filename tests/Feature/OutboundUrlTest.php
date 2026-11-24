<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Setting;
use App\Services\Importing\ApifyImporter;
use App\Services\Importing\ListingImportService;
use App\Support\OutboundUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Addresses users type in that the server fetches (iCal calendars, listings
 * to import, their photos): never an address inside the server's own network,
 * also not through a redirect, and never more than a set size.
 */
class OutboundUrlTest extends TestCase
{
    use RefreshDatabase;

    public static function internalAddresses(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:8080/server-status'],
            'localhost' => ['http://localhost/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'private network' => ['http://10.0.0.5/'],
            'home network' => ['http://192.168.1.1/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'any address' => ['http://0.0.0.0/'],
            'file' => ['file:///etc/passwd'],
            'gopher' => ['gopher://127.0.0.1:6379/_INFO'],
            'credentials' => ['https://user:pass@example.com/'],
        ];
    }

    #[DataProvider('internalAddresses')]
    public function test_an_internal_or_odd_address_is_refused(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        OutboundUrl::check($url);
    }

    public function test_a_public_address_is_allowed(): void
    {
        $this->assertSame(['93.184.215.14'], OutboundUrl::check('https://93.184.215.14/calendar.ics'));
        $this->assertSame(['93.184.215.14'], OutboundUrl::check('https://airbnb.example/calendar.ics'));
    }

    public function test_a_name_that_cannot_be_looked_up_is_refused(): void
    {
        // The real lookup, for a name that does not exist: refused, not left to the HTTP client.
        OutboundUrl::resolveUsing(null);
        $this->expectException(InvalidArgumentException::class);
        OutboundUrl::check('https://does-not-exist.invalid/calendar.ics');
    }

    public function test_a_name_pointing_inside_through_any_of_its_addresses_is_refused(): void
    {
        OutboundUrl::resolveUsing(fn (string $host): array => ['93.184.215.14', '::1']);

        $this->expectException(InvalidArgumentException::class);
        OutboundUrl::check('https://two-faced.example/');
    }

    public function test_the_connection_goes_to_the_checked_address_and_redirects_are_checked_too(): void
    {
        $pins = [];
        Http::fake([
            'https://calendar.example/feed.ics' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            'https://good.example/feed.ics' => Http::response('', 301, ['Location' => '/real.ics']),
            'https://good.example/real.ics' => function (Request $request, array $options) use (&$pins) {
                $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

                return Http::response("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");
            },
        ]);

        // A redirect inside is refused before it is fetched.
        try {
            OutboundUrl::get('https://calendar.example/feed.ics', 1024);
            $this->fail('A redirect to the metadata address was followed.');
        } catch (InvalidArgumentException) {
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));

        // A redirect outside is followed, every request pinned to the checked address.
        $this->assertStringContainsString('BEGIN:VCALENDAR', OutboundUrl::get('https://good.example/feed.ics', 1024)->body());
        $this->assertSame([['good.example:443:93.184.215.14']], $pins);
    }

    public function test_a_calendar_on_an_internal_address_is_not_fetched(): void
    {
        Http::fake();
        $property = Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);
        $connection = $property->channelConnections()->create(['provider' => 'ical', 'name' => 'Airbnb', 'url' => 'http://127.0.0.1:6379/']);

        $this->artisan('channels:sync')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(
            __("This address points inside the server's own network and cannot be fetched.", [], config('app.locale')),
            $connection->refresh()->last_error,
        );
    }

    public function test_a_calendar_error_does_not_show_what_the_other_server_answered(): void
    {
        Http::fake(['airbnb.example/*' => Http::response('SECRET-INTERNAL-PAGE', 500)]);
        $property = Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);
        $connection = $property->channelConnections()->create(['provider' => 'ical', 'name' => 'Airbnb', 'url' => 'https://airbnb.example/calendar.ics']);

        $this->artisan('channels:sync')->assertSuccessful();

        $this->assertStringContainsString('500', (string) $connection->refresh()->last_error);
        $this->assertStringNotContainsString('SECRET', (string) $connection->last_error);
    }

    public function test_imported_photos_only_come_from_outside_and_must_be_photos(): void
    {
        Storage::fake('public');
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();

        Http::fake([
            'cdn.example.com/photo.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'cdn.example.com/page.html' => Http::response('<script>alert(1)</script>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('internal', 200),
        ]);
        $property = Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);

        app(ListingImportService::class)->downloadPhotos($property, [
            'http://169.254.169.254/latest/meta-data/iam',
            'https://cdn.example.com/page.html',
            'https://cdn.example.com/photo.jpg',
        ]);

        $photos = $property->getMedia('photos');
        $this->assertCount(1, $photos);
        $this->assertSame('photo.jpg', $photos->first()->file_name);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_the_import_service_token_does_not_go_into_the_address(): void
    {
        Setting::set('import', 'apify_token', 'apify_secret_token');
        Setting::set('import', 'apify_actor', 'someone~airbnb-scraper');
        Http::fake(['api.apify.com/*' => Http::response([['name' => 'Loft']])]);

        try {
            app(ApifyImporter::class)->import('https://www.airbnb.com/rooms/1');
        } catch (\Throwable) {
            // Only the request matters here.
        }

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.apify.com')
            && ! str_contains($request->url(), 'apify_secret_token')
            && $request->hasHeader('Authorization', 'Bearer apify_secret_token'));
    }
}
