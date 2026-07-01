<?php

use App\Domain\SocialAccounts\LinkedInTokenInspector;
use App\Models\SocialAccount;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->inspector = new LinkedInTokenInspector;
});

test('account with refresh token does not need reauthorization', function () {
    $account = SocialAccount::factory()->linkedinWithRefreshToken()->create([
        'credentials_encrypted' => [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->subDay()->toIso8601String(),
        ],
    ]);

    expect($this->inspector->needsReauthorization($account, now()->addWeek()))->toBeFalse();
});

test('expired linkedin account without refresh token needs reauthorization', function () {
    $account = SocialAccount::factory()->linkedinExpired()->create();

    expect($this->inspector->needsReauthorization($account, now()->addWeek()))->toBeTrue();
});

test('linkedin account expiring before scheduled publish needs reauthorization', function () {
    $account = SocialAccount::factory()->linkedin()->create([
        'credentials_encrypted' => [
            'access_token' => 'token',
            'refresh_token' => null,
            'expires_at' => now()->addDays(2)->toIso8601String(),
        ],
    ]);

    expect($this->inspector->needsReauthorization($account, now()->addWeek()))->toBeTrue();
});

test('linkedin account valid through scheduled publish does not need reauthorization', function () {
    $account = SocialAccount::factory()->linkedin()->create([
        'credentials_encrypted' => [
            'access_token' => 'token',
            'refresh_token' => null,
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ],
    ]);

    expect($this->inspector->needsReauthorization($account, now()->addWeek()))->toBeFalse();
});

test('connection warning is returned when linkedin omits refresh token', function () {
    expect($this->inspector->getConnectionWarning(null))->not->toBeNull();
    expect($this->inspector->getConnectionWarning(''))->not->toBeNull();
    expect($this->inspector->getConnectionWarning('refresh-token'))->toBeNull();
});

test('first owned account needing reauthorization ignores shared accounts', function () {
    $owner = User::factory()->create();
    $editor = User::factory()->create();

    $sharedAccount = SocialAccount::factory()->linkedinExpired()->create(['user_id' => $owner->id]);
    $ownedAccount = SocialAccount::factory()->linkedinExpired()->create(['user_id' => $editor->id]);

    $result = $this->inspector->firstOwnedAccountNeedingReauthorization(
        [$sharedAccount->id, $ownedAccount->id],
        now()->addWeek(),
        $editor,
    );

    expect($result?->id)->toBe($ownedAccount->id);
});

test('accountNeedsAttention is true when linkedin token is expired', function () {
    $account = SocialAccount::factory()->linkedinExpired()->create();
    $inspector = new LinkedInTokenInspector;

    expect($inspector->accountNeedsAttention($account))->toBeTrue();
});

test('accountNeedsAttention is true when linkedin token expires within 14 days', function () {
    $account = SocialAccount::factory()->linkedin()->create([
        'credentials_encrypted' => [
            'access_token' => 'token',
            'refresh_token' => null,
            'expires_at' => now()->addDays(7)->toIso8601String(),
        ],
    ]);
    $inspector = new LinkedInTokenInspector;

    expect($inspector->accountNeedsAttention($account))->toBeTrue();
});
