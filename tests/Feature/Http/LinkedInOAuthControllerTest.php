<?php

use App\Enums\Provider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.linkedin.client_id' => 'linkedin-client-id',
        'services.linkedin.client_secret' => 'linkedin-client-secret',
        'services.linkedin.redirect_uri' => '/social-accounts/connect/linkedin/callback',
    ]);
});

test('redirect sends user to linkedin authorization url', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('social-accounts.linkedin-oauth-redirect'));

    $response->assertRedirect();

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://www.linkedin.com/oauth/v2/authorization');
    expect($location)->toContain('response_type=code');
    expect($location)->toContain('client_id=linkedin-client-id');
    expect($location)->toContain('scope=');
    expect($location)->toContain('state=');

    expect(session('linkedin_oauth_state'))->not->toBeNull();
});

test('redirect shows error when client id is missing', function () {
    config(['services.linkedin.client_id' => null]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('social-accounts.linkedin-oauth-redirect'));

    $response->assertRedirect(route('social-accounts.connect-linkedin'));
    $response->assertSessionHas('error');
});

test('callback rejects invalid state parameter', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['linkedin_oauth_state' => 'valid-state'])
        ->get(route('social-accounts.linkedin-oauth-callback', ['state' => 'wrong-state', 'code' => 'abc']));

    $response->assertRedirect(route('social-accounts.connect-linkedin'));
    $response->assertSessionHas('error', 'Invalid OAuth state. Please try connecting again.');

    expect(SocialAccount::count())->toBe(0);
});

test('callback handles user denial', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('social-accounts.linkedin-oauth-callback', [
            'error' => 'access_denied',
            'error_description' => 'The user denied your request.',
        ]));

    $response->assertRedirect(route('social-accounts.connect-linkedin'));
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('denied');
});

test('successful callback stores linkedin account with encrypted credentials', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'linkedin-access-token',
            'refresh_token' => 'linkedin-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid profile w_member_social',
            'token_type' => 'Bearer',
        ]),
        'api.linkedin.com/v2/userinfo' => Http::response([
            'sub' => 'member-123',
            'name' => 'Test Person',
        ]),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['linkedin_oauth_state' => 'valid-state'])
        ->get(route('social-accounts.linkedin-oauth-callback', [
            'state' => 'valid-state',
            'code' => 'auth-code',
        ]));

    $response->assertRedirect(route('social-accounts.index'));
    $response->assertSessionHas('message');

    $account = SocialAccount::where('user_id', $user->id)->first();

    expect($account)->not->toBeNull();
    expect($account->provider)->toBe(Provider::LinkedIn);
    expect($account->display_name)->toBe('Test Person');
    expect($account->external_identifier)->toBe('member-123');

    $creds = $account->credentials_encrypted;
    expect($creds['access_token'])->toBe('linkedin-access-token');
    expect($creds['refresh_token'])->toBe('linkedin-refresh-token');
    expect($creds['member_id'])->toBe('member-123');
    expect($creds['expires_at'])->not->toBeNull();
});

test('callback handles token exchange failure', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Authorization code invalid.',
        ], 400),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['linkedin_oauth_state' => 'valid-state'])
        ->get(route('social-accounts.linkedin-oauth-callback', [
            'state' => 'valid-state',
            'code' => 'bad-code',
        ]));

    $response->assertRedirect(route('social-accounts.connect-linkedin'));
    $response->assertSessionHas('error');
    expect(SocialAccount::count())->toBe(0);
});

test('callback handles user info fetch failure', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'linkedin-access-token',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ]),
        'api.linkedin.com/v2/userinfo' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['linkedin_oauth_state' => 'valid-state'])
        ->get(route('social-accounts.linkedin-oauth-callback', [
            'state' => 'valid-state',
            'code' => 'auth-code',
        ]));

    $response->assertRedirect(route('social-accounts.connect-linkedin'));
    $response->assertSessionHas('error');
    expect(SocialAccount::count())->toBe(0);
});

test('connect linkedin page requires authentication', function () {
    $this->get(route('social-accounts.connect-linkedin'))
        ->assertRedirect(route('login'));
});

test('linkedin redirect requires authentication', function () {
    $this->get(route('social-accounts.linkedin-oauth-redirect'))
        ->assertRedirect(route('login'));
});

test('linkedin callback requires authentication', function () {
    $this->get(route('social-accounts.linkedin-oauth-callback'))
        ->assertRedirect(route('login'));
});
