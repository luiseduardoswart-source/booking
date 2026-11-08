<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Filament\Resources\ChannelConnections\Pages\ManageChannelConnections;
use App\Filament\Resources\Coupons\Pages\ManageCoupons;
use App\Filament\Resources\Pages\Pages\ManagePages;
use App\Filament\Resources\Properties\Pages\EditProperty;
use App\Filament\Resources\Properties\Pages\ListProperties;
use App\Models\AdminLanguage;
use App\Models\ChannelConnection;
use App\Models\Coupon;
use App\Models\Language;
use App\Models\Page;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\Languages;
use App\Support\Access;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * On/off switches in lists save immediately. In forms, Save is still needed,
 * and anyone who navigates away with pending changes is asked first.
 */
class DirectSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    private function accommodation(): Property
    {
        return Property::create([
            'name' => 'Strandhuis', 'slug' => 'strandhuis-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 4, 'active' => true, 'base_price_cents' => 10000, 'country' => 'NL',
        ]);
    }

    /** The path of the switch of one language in the list. */
    private function languagePath(Testable $page, string $list, string $code): string
    {
        $key = collect($page->get("data.{$list}"))->search(fn (array $row) => $row['code'] === $code);
        $this->assertNotFalse($key, $code);

        return "data.{$list}.{$key}.active";
    }

    /** The way Filament remembers the saved form. */
    private function isSaved(Testable $page): bool
    {
        $hash = md5((string) str(json_encode($page->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', ''));

        return $hash === $page->get('savedDataHash');
    }

    public function test_a_website_language_switch_off_saves_immediately_on(): void
    {
        $this->assertContains('es', Languages::active());

        $page = Livewire::actingAs($this->owner())->test(Settings::class);
        $page->set($this->languagePath($page, 'languages', 'es'), false);

        $this->assertFalse(Language::where('code', 'es')->value('active'));
        $this->assertNotContains('es', Languages::active());
        Notification::assertNotified(__('Saved'));
    }

    public function test_a_admin_language_switch_on_saves_immediately_on_and_affects_the_website_not(): void
    {
        AdminLanguage::where('code', 'fr')->update(['active' => false]);
        $site = Language::where('code', 'fr')->value('active');

        $page = Livewire::actingAs($this->owner())->test(Settings::class);
        $page->set($this->languagePath($page, 'admin_languages', 'fr'), true);

        $this->assertTrue(AdminLanguage::where('code', 'fr')->value('active'));
        $this->assertSame($site, Language::where('code', 'fr')->value('active'));
    }

    public function test_the_default_language_stays_on(): void
    {
        $default = Languages::default();

        $page = Livewire::actingAs($this->owner())->test(Settings::class);
        $path = $this->languagePath($page, 'languages', $default);
        $page->set($path, false);

        $this->assertTrue(Language::where('code', $default)->value('active'));
        $this->assertTrue($page->get($path));
        Notification::assertNotified(__('The default language is always on. Choose another default language first.'));
    }

    public function test_a_new_language_waits_on_save(): void
    {
        $page = Livewire::actingAs($this->owner())->test(Settings::class);
        $page->set('data.languages.new', ['code' => 'it', 'name' => 'Italiano', 'active' => true]);
        $page->set('data.languages.new.active', false);

        $this->assertDatabaseMissing('languages', ['code' => 'it']);
    }

    public function test_after_direct_save_only_a_warning_as_there_still_something_is_outstanding(): void
    {
        $page = Livewire::actingAs($this->owner())->test(Settings::class);
        $this->assertTrue($this->isSaved($page));

        $page->set($this->languagePath($page, 'languages', 'es'), false);
        $this->assertTrue($this->isSaved($page), 'Only the switch changed and saved: no warning.');

        $page->set('data.company_name', 'Nieuwe naam');
        $page->set($this->languagePath($page, 'languages', 'de'), false);
        $this->assertFalse($this->isSaved($page), 'The name is not saved yet: the warning must stay.');

        $page->call('save');
        $this->assertTrue($this->isSaved($page));
    }

    public function test_active_in_the_accommodation_form_saves_immediately_on(): void
    {
        $accommodation = $this->accommodation();

        $page = Livewire::actingAs($this->owner())
            ->test(EditProperty::class, ['record' => $accommodation->getRouteKey()])
            ->set('data.active', false);

        $this->assertFalse($accommodation->fresh()->active);
        $this->assertTrue($this->isSaved($page));
        Notification::assertNotified(__('Saved'));
    }

    public function test_a_switch_at_the_settings_saves_immediately_on(): void
    {
        $page = Livewire::actingAs($this->owner())->test(Settings::class)
            ->set('data.cookie_on', true);

        $this->assertSame('1', Setting::get('cookie', 'on'));
        $this->assertTrue($this->isSaved($page));

        $page->set('data.site_single_accommodation_direct', false);
        $this->assertSame('0', Setting::get('site', 'single_accommodation_direct'));
    }

    public function test_the_admin_warns_at_leaving_with_changes(): void
    {
        $this->actingAs($this->owner());

        $this->get(Settings::getUrl())->assertOk()
            ->assertSee('setUpFilamentUnsavedDataChangesAlert', false);
        $this->get(EditProperty::getUrl(['record' => $this->accommodation()]))->assertOk()
            ->assertSee('setUpFilamentUnsavedDataChangesAlert', false);
    }

    public function test_switches_in_the_lists_store_immediately_on(): void
    {
        $accommodation = $this->accommodation();
        $page = Page::create(['title' => 'Over ons', 'published' => true, 'in_menu' => true, 'in_footer' => false]);
        $coupon = Coupon::create(['code' => 'TIEN', 'type' => Coupon::TYPE_PERCENT, 'value' => 10, 'active' => true]);
        $link = ChannelConnection::create(['property_id' => $accommodation->id, 'name' => 'Airbnb', 'url' => 'https://example.com/ical', 'active' => true]);

        Livewire::actingAs($this->owner());

        foreach ([
            [ListProperties::class, $accommodation, 'active', false],
            [ManagePages::class, $page, 'published', false],
            [ManagePages::class, $page, 'in_menu', false],
            [ManagePages::class, $page, 'in_footer', true],
            [ManageCoupons::class, $coupon, 'active', false],
            [ManageChannelConnections::class, $link, 'active', false],
        ] as [$list, $record, $field, $value]) {
            Livewire::test($list)->call('updateTableColumnState', $field, (string) $record->getKey(), $value);

            $this->assertSame($value, $record->fresh()->{$field}, "{$list} {$field}");
        }

        Notification::assertNotified(__('Saved'));
    }

    public function test_without_edit_permission_changes_a_switch_nothing(): void
    {
        $page = Page::create(['title' => 'Over ons', 'published' => true]);
        $onlyLook = User::factory()->create([
            'role' => UserRole::Staff,
            'permissions' => ['pages' => [Access::VIEW]],
        ]);

        Livewire::actingAs($onlyLook)->test(ManagePages::class)
            ->call('updateTableColumnState', 'published', (string) $page->getKey(), false);

        $this->assertTrue($page->fresh()->published);
    }
}
