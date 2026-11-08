<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Everyone in the admin can drag the menu on the left into a different
 * order; the order belongs to their own account.
 */
class MenuOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function link(string $path): string
    {
        return 'href="'.url($path).'"';
    }

    private function owner(?array $order = null): User
    {
        $user = User::factory()->create(['role' => UserRole::Administrator]);
        $user->forceFill(['menu_order' => $order])->save();

        return $user;
    }

    public function test_without_own_order_is_the_menu_as_always(): void
    {
        $this->actingAs($this->owner())
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder([$this->link('/admin/amenities'), $this->link('/admin/payments')], false)
            ->assertSee(__('Reorder menu'));
    }

    public function test_the_menu_follows_the_dragged_order_and_the_rest_comes_behind_it(): void
    {
        $this->actingAs($this->owner(['/admin/payments', '/admin/amenities']))
            ->get('/admin')
            ->assertOk()
            ->assertSeeInOrder([
                $this->link('/admin/payments'),
                $this->link('/admin/amenities'),
                $this->link('/admin/bookings'), // not dragged: after that, in the usual order
            ], false);
    }

    public function test_the_order_is_per_user(): void
    {
        $this->owner(['/admin/payments', '/admin/amenities']);

        $this->actingAs($this->owner())
            ->get('/admin')
            ->assertSeeInOrder([$this->link('/admin/amenities'), $this->link('/admin/payments')], false);
    }

    public function test_save_saves_only_paths_without_duplicates(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->postJson('/admin-menu/order', ['order' => [
                'https://www.example.nl/admin/payments?tab=1',
                '/admin/bookings/',
                '/admin/payments',
            ]])
            ->assertNoContent();

        $this->assertSame(['/admin/payments', '/admin/bookings'], $owner->fresh()->menu_order);

        // Empty list: back to the default order.
        $this->actingAs($owner)->postJson('/admin-menu/order', ['order' => []])->assertNoContent();
        $this->assertNull($owner->fresh()->menu_order);
    }

    public function test_only_who_in_the_admin_may_can_a_order_save(): void
    {
        $this->postJson('/admin-menu/order', ['order' => []])->assertUnauthorized();

        $guest = User::factory()->create(['role' => UserRole::Guest]);
        $this->actingAs($guest)->postJson('/admin-menu/order', ['order' => ['/admin']])->assertForbidden();
        $this->assertNull($guest->fresh()->menu_order);
    }

    public function test_nonsensical_input_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/admin-menu/order', ['order' => 'geen lijst'])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/admin-menu/order', ['order' => array_fill(0, 101, '/admin')])->assertUnprocessable();
    }
}
