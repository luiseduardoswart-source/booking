<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\ClaudeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Claude connection itself: off until the owner switches it on, only
 * Claude can register, logging in and "Allow" go through the admin, and only
 * with a valid token and an admin account does /mcp answer.
 */
class ClaudeConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Signing keys in a folder of their own, made once: never the real storage/.
        $keys = sys_get_temp_dir().DIRECTORY_SEPARATOR.'claude-connection-test-keys';
        is_dir($keys) || mkdir($keys);
        Passport::loadKeysFrom($keys);
        ClaudeConnection::ensureKeys();
    }

    private function switchOn(): void
    {
        Setting::set('claude', 'enabled', '1');
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Administrator]);
    }

    /** A JSON-RPC message to the MCP address, with a token. */
    private function mcp(string $token, string $method, array $params = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /**
     * The whole connection as Claude makes it: register, the person logs in
     * and allows, the code becomes a token.
     */
    private function connect(User $user): string
    {
        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])
            ->assertCreated()->json('client_id');

        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $screen = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code',
            'scope' => 'mcp:use', 'state' => 'abc', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]))->assertOk()->assertSee(__('Allow'));

        preg_match('/name="auth_token" value="([^"]+)"/', $screen->getContent(), $authToken);

        $redirect = $this->post('/oauth/authorize', ['state' => 'abc', 'client_id' => $client, 'auth_token' => $authToken[1]])
            ->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith(self::CALLBACK, $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        $token = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => self::CALLBACK,
            'code_verifier' => $verifier, 'code' => $query['code'],
        ])->assertOk()->json('access_token');

        // From here on only the token counts, not the admin session.
        auth()->forgetGuards();
        $this->flushSession();

        return $token;
    }

    public function test_nothing_exists_until_the_owner_switches_it_on(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->postJson('/oauth/register', ['redirect_uris' => [self::CALLBACK]])->assertNotFound();
        $this->get('/oauth/authorize')->assertNotFound();
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();

        $this->switchOn();

        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_without_a_token_mcp_says_how_to_log_in(): void
    {
        $this->switchOn();

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');

        $this->mcp('not-a-token', 'tools/list')->assertUnauthorized();
    }

    public function test_only_claude_can_register_as_an_app(): void
    {
        $this->switchOn();

        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://evil.example/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai.evil.example/callback']])
            ->assertStatus(400);
        $this->postJson('/oauth/register', ['client_name' => 'App', 'redirect_uris' => ['cursor://callback']])
            ->assertStatus(400);

        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])->assertCreated();
        // Claude Code on the person's own computer.
        $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:51234/callback']])->assertCreated();
    }

    public function test_allowing_goes_through_the_admin_login(): void
    {
        $this->switchOn();

        $client = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])->json('client_id');

        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code', 'scope' => 'mcp:use',
            'state' => 'abc', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_after_allowing_claude_works_with_the_rights_of_that_person(): void
    {
        $this->switchOn();
        Property::create(['name' => 'Beach house', 'slug' => 'beach-house', 'type' => 'holiday_home', 'max_guests' => 2, 'active' => true]);

        $token = $this->connect($this->owner());

        $tools = collect($this->mcp($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
        $this->assertContains('list_accommodations', $tools);
        $this->assertContains('update_style', $tools);

        $this->mcp($token, 'tools/call', ['name' => 'list_accommodations', 'arguments' => []])
            ->assertOk()->assertSee('Beach house');
    }

    public function test_someone_who_cannot_use_the_admin_gets_no_access(): void
    {
        $this->switchOn();
        $owner = $this->owner();
        $token = $this->connect($owner);

        // The role changes after connecting: access ends at once.
        $owner->update(['role' => UserRole::Guest]);

        $this->mcp($token, 'tools/list')->assertForbidden();
    }

    public function test_switching_off_ends_every_connection(): void
    {
        $this->switchOn();
        $token = $this->connect($this->owner());
        $this->assertCount(1, ClaudeConnection::connections());

        Setting::set('claude', 'enabled', '0');
        ClaudeConnection::switched(true);

        $this->assertSame(0, Token::query()->where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::query()->where('revoked', false)->count());
        $this->assertCount(0, ClaudeConnection::connections());

        // Switched on again, the old token does not come back to life.
        $this->switchOn();
        $this->mcp($token, 'tools/list')->assertUnauthorized();
    }

    public function test_the_owner_switches_it_on_in_settings_and_sees_the_address(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)->test(Settings::class)
            ->assertFormFieldHidden('claude_url')
            ->set('data.claude_enabled', true)
            ->assertFormFieldVisible('claude_url')
            ->assertFormSet(['claude_url' => ClaudeConnection::url()]);

        $this->assertTrue(ClaudeConnection::enabled());
    }

    public function test_only_the_owner_may_switch_it(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        Livewire::actingAs($staff)->test(Settings::class)->assertForbidden();
        $this->assertFalse(ClaudeConnection::enabled());
    }
}
