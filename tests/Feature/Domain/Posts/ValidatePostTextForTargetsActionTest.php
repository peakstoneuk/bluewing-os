<?php

use App\Domain\Posts\PostData;
use App\Domain\Posts\ValidatePostTextForTargetsAction;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialProviders\Bluesky\BlueskyTextLimits;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function blueskyLongText(int $graphemes = 301): string
{
    return str_repeat('a', $graphemes);
}

test('returns no errors when no bluesky targets are selected', function () {
    $user = User::factory()->create();
    $xAccount = SocialAccount::factory()->x()->create(['user_id' => $user->id]);

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: blueskyLongText(),
        targetAccountIds: [$xAccount->id],
    ));

    expect($errors)->toBeEmpty();
});

test('validates default text against bluesky grapheme limit', function () {
    $user = User::factory()->create();
    $bsAccount = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);
    $longText = blueskyLongText();

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: $longText,
        targetAccountIds: [$bsAccount->id],
    ));

    expect($errors)->toHaveKey('body_text');
    expect($errors['body_text'][0])->toBe(BlueskyTextLimits::errorMessage($longText));
});

test('does not validate default text when a valid bluesky provider override is set', function () {
    $user = User::factory()->create();
    $bsAccount = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: blueskyLongText(),
        targetAccountIds: [$bsAccount->id],
        providerOverrides: ['bluesky' => 'Short Bluesky text'],
    ));

    expect($errors)->toBeEmpty();
});

test('does not validate default text when a valid bluesky account override is set', function () {
    $user = User::factory()->create();
    $bsAccount = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: blueskyLongText(),
        targetAccountIds: [$bsAccount->id],
        accountOverrides: [$bsAccount->id => 'Short account text'],
    ));

    expect($errors)->toBeEmpty();
});

test('validates bluesky provider override when it exceeds the grapheme limit', function () {
    $user = User::factory()->create();
    $bsAccount = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);
    $longOverride = blueskyLongText();

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: 'Short default text',
        targetAccountIds: [$bsAccount->id],
        providerOverrides: ['bluesky' => $longOverride],
    ));

    expect($errors)->toHaveKey('provider_overrides.bluesky');
    expect($errors)->not->toHaveKey('body_text');
});

test('validates account override independently of default text', function () {
    $user = User::factory()->create();
    $bsAccount = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);
    $longOverride = blueskyLongText();

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: blueskyLongText(),
        targetAccountIds: [$bsAccount->id],
        accountOverrides: [$bsAccount->id => $longOverride],
    ));

    expect($errors)->toHaveKey("account_overrides.{$bsAccount->id}");
    expect($errors)->not->toHaveKey('body_text');
});

test('validates default text for bluesky accounts without overrides when another account has a valid override', function () {
    $user = User::factory()->create();
    $bsAccountOne = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);
    $bsAccountTwo = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: blueskyLongText(),
        targetAccountIds: [$bsAccountOne->id, $bsAccountTwo->id],
        accountOverrides: [$bsAccountOne->id => 'Short override for account one'],
    ));

    expect($errors)->toHaveKey('body_text');
    expect($errors)->not->toHaveKey("account_overrides.{$bsAccountOne->id}");
});

test('account override takes precedence over provider override for validation', function () {
    $user = User::factory()->create();
    $bsAccount = SocialAccount::factory()->bluesky()->create(['user_id' => $user->id]);

    $errors = (new ValidatePostTextForTargetsAction)->execute(new PostData(
        scheduledFor: now()->addHour()->toDateTimeString(),
        bodyText: blueskyLongText(),
        targetAccountIds: [$bsAccount->id],
        providerOverrides: ['bluesky' => blueskyLongText()],
        accountOverrides: [$bsAccount->id => 'Valid account override'],
    ));

    expect($errors)->toBeEmpty();
    expect($errors)->not->toHaveKey('provider_overrides.bluesky');
});
