<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Settings;
use App\Models\Property;
use App\Models\Setting;
use App\Models\User;
use App\Support\McpConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI connection itself: off until the owner switches it on, only the
 * apps the owner allows can register, logging in and "Allow" go through the admin, and only
 * with a valid token and an admin account does /mcp answer.
 */
class McpConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected function setUp(): void
    {
        parent::setUp();

        // Signing keys in a folder of their own, made once: never the real storage/.
        $keys = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mcp-connection-test-keys';
        is_dir($keys) || mkdir($keys);
        Passport::loadKeysFrom($keys);
        McpConnection::ensureKeys();
    }

    private function switchOn(): void
    {
        Setting::set('mcp', 'enabled', '1');
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
     * The whole connection as an AI app makes it: register, the person logs in
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

    public function test_a_new_password_ends_the_connections_and_sessions_of_that_person_only(): void
    {
        $this->switchOn();
        $owner = $this->owner();
        $colleague = $this->owner();
        $ownerToken = $this->connect($owner);
        // The colleague's connection: a copy of the token row under their account.
        $colleagueTokenRow = Token::query()->firstOrFail()->replicate();
        $colleagueTokenRow->forceFill(['id' => Str::random(80), 'user_id' => $colleague->id])->save();

        $this->mcp($ownerToken, 'tools/list')->assertOk();

        $owner->update(['password' => 'a-brand-new-long-password']);

        auth()->forgetGuards();
        $this->mcp($ownerToken, 'tools/list')->assertUnauthorized();
        $this->assertFalse((bool) $colleagueTokenRow->refresh()->revoked);
    }

    public function test_a_session_from_before_a_new_password_cannot_allow_an_app(): void
    {
        $this->switchOn();
        $person = $this->owner();
        $allowScreen = '/oauth/authorize?'.http_build_query(['client_id' => 'x', 'response_type' => 'code']);

        // The session knows the password of the moment it signed in...
        $this->actingAs($person)->get($allowScreen);
        $person->update(['password' => 'a-brand-new-long-password']);

        // ...so after a new one it is signed out, and the screen asks to sign in.
        $this->get($allowScreen)->assertRedirect(route('filament.admin.auth.login'));
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

    private function register(string $redirect): TestResponse
    {
        return $this->postJson('/oauth/register', ['client_name' => 'Some app', 'redirect_uris' => [$redirect]]);
    }

    public function test_the_known_ai_apps_can_register_and_nothing_else(): void
    {
        $this->switchOn();

        // Nobody chose yet: all known apps.
        $this->register(self::CALLBACK)->assertCreated();
        $this->register('https://chatgpt.com/connector_platform_oauth_redirect')->assertCreated();
        $this->register('https://vscode.dev/redirect')->assertCreated();
        $this->register('cursor://anysphere.cursor-mcp/oauth/callback')->assertCreated();
        // Claude Code, Gemini CLI and the like on the person's own computer.
        $this->register('http://localhost:51234/callback')->assertCreated();

        // A look-alike or unknown website cannot collect the access code.
        $this->register('https://evil.example/callback')->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->register('https://claude.ai.evil.example/callback')->assertStatus(400);
        $this->register('javascript://claude.ai/%0aalert(1)')->assertStatus(400);
        $this->register('evilapp://callback')->assertStatus(400);
    }

    public function test_the_owner_chooses_which_apps_may_connect(): void
    {
        $this->switchOn();
        Setting::set('mcp', 'apps', 'chatgpt');
        Setting::set('mcp', 'other_addresses', "https://agent.example.com/oauth/callback\nnot an address");

        $this->register('https://chatgpt.com/connector_platform_oauth_redirect')->assertCreated();
        $this->register('https://agent.example.com/oauth/callback')->assertCreated();
        $this->register(self::CALLBACK)->assertStatus(400);
        $this->register('http://localhost:51234/callback')->assertStatus(400);
        $this->register('cursor://anysphere.cursor-mcp/oauth/callback')->assertStatus(400);
    }

    public function test_an_app_the_owner_no_longer_allows_cannot_log_in(): void
    {
        $this->switchOn();
        $client = $this->register(self::CALLBACK)->json('client_id');

        Setting::set('mcp', 'apps', 'chatgpt');

        $this->actingAs($this->owner())->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'response_type' => 'code', 'scope' => 'mcp:use',
            'state' => 'abc', 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256',
        ]))->assertForbidden();
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

    public function test_after_allowing_the_app_works_with_the_rights_of_that_person(): void
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
        $this->assertCount(1, McpConnection::connections());

        Setting::set('mcp', 'enabled', '0');
        McpConnection::switched(true);

        $this->assertSame(0, Token::query()->where('revoked', false)->count());
        $this->assertSame(0, RefreshToken::query()->where('revoked', false)->count());
        $this->assertCount(0, McpConnection::connections());

        // Switched on again, the old token does not come back to life.
        $this->switchOn();
        $this->mcp($token, 'tools/list')->assertUnauthorized();
    }

    public function test_the_owner_switches_it_on_in_settings_and_sees_the_address(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)->test(Settings::class)
            ->assertFormFieldHidden('mcp_url')
            ->set('data.mcp_enabled', true)
            ->assertFormFieldVisible('mcp_url')
            ->assertFormSet(['mcp_url' => McpConnection::url()])
            ->set('data.mcp_apps', ['claude', 'local']);

        $this->assertSame(['claude', 'local'], McpConnection::allowedApps());

        $this->assertTrue(McpConnection::enabled());
    }

    public function test_only_the_owner_may_switch_it(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        Livewire::actingAs($staff)->test(Settings::class)->assertForbidden();
        $this->assertFalse(McpConnection::enabled());
    }
}
