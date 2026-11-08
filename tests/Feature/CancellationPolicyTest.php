<?php

namespace Tests\Feature;

use App\Enums\CancellationPolicy;
use App\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancellationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function accommodation(array $fields = []): Property
    {
        return Property::create([
            'name' => 'Huis', 'slug' => 'house-'.uniqid(), 'type' => 'holiday_home',
            'max_guests' => 2, 'active' => true, ...$fields,
        ]);
    }

    public function test_the_built_in_text_applies_without_own_text(): void
    {
        $this->assertSame(
            CancellationPolicy::Moderate->defaultLines(),
            $this->accommodation()->cancellationRules(CancellationPolicy::Moderate),
        );
    }

    public function test_the_default_text_overrides_the_built_in_text(): void
    {
        app()->setLocale('nl');
        Property::master()->update(['cancellation_texts' => ['moderate' => ['nl' => "Eerste regel\nTweede regel"]]]);

        $accommodation = $this->accommodation();

        $this->assertSame(['Eerste regel', 'Tweede regel'], $accommodation->cancellationRules(CancellationPolicy::Moderate));

        // Another policy keeps using the built-in text.
        $this->assertSame(CancellationPolicy::Strict->defaultLines(), $accommodation->cancellationRules(CancellationPolicy::Strict));
    }

    public function test_the_accommodation_wins_over_the_default(): void
    {
        app()->setLocale('nl');
        Property::master()->update(['cancellation_texts' => ['strict' => ['nl' => 'Van de standaard']]]);

        $accommodation = $this->accommodation(['cancellation_texts' => ['strict' => ['nl' => 'Van dit huis']]]);

        $this->assertSame(['Van dit huis'], $accommodation->cancellationRules(CancellationPolicy::Strict));
    }

    public function test_the_guest_sees_the_accommodation_text_for_its_policy(): void
    {
        app()->setLocale('nl');
        Property::master()->update(['cancellation_policy' => CancellationPolicy::Strict]);

        $accommodation = $this->accommodation(['cancellation_texts' => ['strict' => ['nl' => 'Van dit huis']]]);

        $this->assertSame(['Van dit huis'], $accommodation->cancellationPolicyLines());
    }

    public function test_a_custom_policy_has_no_built_in_rules(): void
    {
        $this->assertSame([], CancellationPolicy::Custom->lines());
        $this->assertSame([], $this->accommodation()->cancellationRules(CancellationPolicy::Custom));
    }
}
