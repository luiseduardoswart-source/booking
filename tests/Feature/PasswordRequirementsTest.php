<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\InstallCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * The password requirements of the administrator account (AppServiceProvider): at least
 * 15 characters, at most 64, no composition rules, and not leaked.
 */
class PasswordRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(string $password)
    {
        InstallCode::ensureCode();

        return $this->withSession(['install_code_ok' => true])->post('/install/account', [
            'name' => 'Luis',
            'email' => 'luis@example.nl',
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_fifteen_characters_is_enough(): void
    {
        $this->makeAccount('fifteen-characters')->assertRedirect('/admin');

        $this->assertNotNull(User::firstWhere('email', 'luis@example.nl'));
    }

    public function test_fourteen_characters_is_rejected(): void
    {
        $this->makeAccount('fourteen-tekns')->assertSessionHasErrors('password');

        $this->assertNull(User::firstWhere('email', 'luis@example.nl'));
    }

    public function test_only_small_letters_may_because_length_counts_not_composition(): void
    {
        $this->makeAccount('kortekaasbroodje')->assertRedirect('/admin');
    }

    public function test_more_then_sixty_four_characters_is_rejected(): void
    {
        $this->makeAccount(str_repeat('a', 65))->assertSessionHasErrors('password');
    }

    public function test_a_leaked_password_is_rejected(): void
    {
        $password = 'this-is-unfortunately-leaked';
        $hash = strtoupper(sha1($password));

        // Have I Been Pwned answers with the rest of the hash plus how often it
        // appeared in leaks. Only the first 5 characters are sent.
        $this->leakCheck = fn () => Http::response(substr($hash, 5).':1337', 200);

        $this->makeAccount($password)->assertSessionHasErrors('password');

        $this->assertNull(User::firstWhere('email', 'luis@example.nl'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/range/'.substr($hash, 0, 5)));
    }

    public function test_only_the_start_of_the_hash_leaves_the_server(): void
    {
        $password = 'a-secret-password';

        $this->makeAccount($password);

        Http::assertSent(function ($request) use ($password) {
            return ! str_contains($request->url(), $password)
                && ! str_contains($request->url(), strtoupper(sha1($password)));
        });
    }

    public function test_installation_goes_through_as_the_leak_check_unreachable_is(): void
    {
        $this->leakCheck = fn () => Http::response('', 503);

        $this->makeAccount('service-lies-out-but-ok')->assertRedirect('/admin');
    }

    public function test_the_page_shows_same_requirements_as_the_server_enforces(): void
    {
        InstallCode::ensureCode();

        $rules = Password::default()->appliedRules();

        $this->withSession(['install_code_ok' => true])
            ->get('/install/account')
            ->assertOk()
            ->assertSee('minlength="'.$rules['min'].'"', false)
            ->assertSee('maxlength="'.$rules['max'].'"', false)
            ->assertSee('data-min="'.$rules['min'].'"', false)
            ->assertSee('js/password.js', false)
            ->assertSee(__('Suggest'));
    }
}
