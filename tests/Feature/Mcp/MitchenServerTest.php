<?php

use App\Models\Ingredient;
use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function () {
    // Passport's signing keys, which `php artisan passport:keys` writes outside tests.
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $privateKey);

    config([
        'passport.private_key' => $privateKey,
        'passport.public_key' => openssl_pkey_get_details($key)['key'],
    ]);
});

/**
 * @return array<string, mixed>
 */
function listInventoryRequest(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list-inventory', 'arguments' => []],
    ];
}

it('turns away requests without a token, pointing the client at the sign-in', function (array $headers) {
    $this->postJson(route('mcp'), listInventoryRequest(), $headers)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
})->with([
    'no token' => [[]],
    'unknown token' => [['Authorization' => 'Bearer not-a-real-token']],
]);

it('tells clients where to sign in', function () {
    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJson(['resource' => url('/mcp'), 'authorization_servers' => [url('/')]]);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJson([
            'authorization_endpoint' => url('/oauth/authorize'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/register'),
            'code_challenge_methods_supported' => ['S256'],
        ]);
});

it('answers tool calls for a signed-in cook', function () {
    Passport::actingAs(User::factory()->create());
    Ingredient::factory()->create(['name' => 'Jasmine rice']);

    $this->postJson(route('mcp'), listInventoryRequest())
        ->assertOk()
        ->assertJsonPath('result.structuredContent.ingredients.0.name', 'Jasmine rice');
});

it('sends a cook who isn\'t signed in to the login before approving a client', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'code_challenge' => str_repeat('c', 43),
        'code_challenge_method' => 'S256',
    ]))->assertRedirect(route('filament.admin.auth.login'));
});

it('lets the Claude app register, get the cook\'s approval and call tools with its token', function () {
    $cook = User::factory()->create(['email' => 'cook@example.com']);
    Ingredient::factory()->create(['name' => 'Jasmine rice']);
    $callback = 'https://claude.ai/api/mcp/auth_callback';
    $verifier = str_repeat('v', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $clientId = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$callback]])
        ->assertCreated()
        ->json('client_id');

    $consent = $this->actingAs($cook)
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $callback,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'kitchen',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Connect Claude?')
        ->assertSee('Signed in as cook@example.com')
        ->assertSee('An app calling itself <strong>Claude</strong> wants to connect, and will send you back to <strong>claude.ai</strong>.', escape: false);

    $redirect = $this->post(route('passport.authorizations.approve'), [
        'state' => '',
        'client_id' => $clientId,
        'auth_token' => $consent->viewData('authToken'),
    ])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($redirect)->toStartWith($callback)
        ->and($query['state'])->toBe('kitchen');

    $tokens = $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $callback,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json();

    // Access lasts a day at a time.
    expect($tokens['expires_in'])->toBeBetween(86_000, 86_400);

    $this->app['auth']->forgetGuards();

    $this->withToken($tokens['access_token'])
        ->postJson(route('mcp'), listInventoryRequest())
        ->assertOk()
        ->assertJsonPath('result.structuredContent.ingredients.0.name', 'Jasmine rice');

    // The client renews it with its refresh token, which is replaced each time.
    $refresh = fn () => $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'refresh_token' => $tokens['refresh_token'],
    ]);

    $renewed = $refresh()->assertOk()->json();

    expect($renewed['access_token'])->not->toBe($tokens['access_token'])
        ->and($renewed['refresh_token'])->not->toBe($tokens['refresh_token']);

    $refresh()->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $this->app['auth']->forgetGuards();

    $this->withToken($renewed['access_token'])
        ->postJson(route('mcp'), listInventoryRequest())
        ->assertOk();
});

it('only lets a client register to send the cook back to Claude or to the cook\'s own machine', function (string $callback, bool $allowed) {
    $response = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$callback]]);

    $allowed
        ? $response->assertCreated()
        : $response->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

    expect(Passport::client()->newQuery()->count())->toBe($allowed ? 1 : 0);
})->with([
    'the Claude apps' => ['https://claude.ai/api/mcp/auth_callback', true],
    'Claude Code, on localhost' => ['http://localhost:3118/callback', true],
    'Claude Code, on the loopback address' => ['http://127.0.0.1:51234/callback', true],
    'another site' => ['https://anything.example/callback', false],
    'a site named to look like Claude' => ['https://claude.ai.anything.example/callback', false],
    'Claude without HTTPS' => ['http://claude.ai/api/mcp/auth_callback', false],
]);

it('lets another client register once its address is listed in MCP_REDIRECT_DOMAINS', function () {
    config(['mcp.redirect_domains' => ['https://claude.ai', 'http://localhost', 'https://assistant.example']]);

    $this->postJson('/oauth/register', ['client_name' => 'Another assistant', 'redirect_uris' => ['https://assistant.example/callback']])
        ->assertCreated();
});

it('has no device sign-in pages', function () {
    $this->get('/oauth/device')->assertNotFound();
    $this->postJson('/oauth/device/code')->assertNotFound();
});
