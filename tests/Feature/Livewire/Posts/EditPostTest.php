<?php

use App\Enums\PostStatus;
use App\Enums\ScopeType;
use App\Livewire\Posts\EditPost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('edit post page requires authentication', function () {
    $post = Post::factory()->create();

    $this->get(route('posts.edit', $post))
        ->assertRedirect(route('login'));
});

test('non-creator cannot access edit page', function () {
    $creator = User::factory()->create();
    $other = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $creator->id]);

    Livewire::actingAs($other)
        ->test(EditPost::class, ['post' => $post])
        ->assertForbidden();
});

test('creator can load edit page with existing data', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->x()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Original text',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post]);

    $component->assertSet('body_text', 'Original text');
    $component->assertSet('selected_accounts', [$account->id]);
});

test('creator can update a draft post', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->x()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'scheduled_for' => now()->addDay(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Old text',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post])
        ->set('body_text', 'Updated text')
        ->set('scheduled_for', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('save', 'schedule')
        ->assertRedirect(route('posts.edit', $post));

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled);
    expect($post->variants->first()->body_text)->toBe('Updated text');
});

test('cannot edit a queued post', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->x()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Queued,
        'scheduled_for' => now()->addDay(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Queued text',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post])
        ->set('body_text', 'Tried to change')
        ->call('save', 'draft')
        ->assertHasNoErrors();

    $post->refresh();

    // Status should not have changed
    expect($post->status)->toBe(PostStatus::Queued);
    expect($post->variants->first()->body_text)->toBe('Queued text');
});

test('queued post shows view mode with info box and content', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->x()->create(['user_id' => $user->id, 'display_name' => '@viewtest']);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Queued,
        'scheduled_for' => now()->addDay(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Visible queued body',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post]);

    $component->assertSee('View Post');
    $component->assertSee('This post can no longer be edited');
    $component->assertSee('It has already been queued or published');
    $component->assertSee('Visible queued body');
    $component->assertSee('@viewtest');
    $component->assertSee('Back to dashboard');

    // View mode: no Schedule or Save as Draft buttons (only Back to dashboard)
    $html = $component->html();
    expect($html)->not->toContain('Save as Draft');
});

test('opening editable post with expired linkedin target redirects to linkedin oauth', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->linkedinExpired()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_for' => now()->addDay(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Scheduled LinkedIn post',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->get(route('posts.edit', $post))
        ->assertRedirect(route('social-accounts.linkedin-oauth-redirect'));

    expect(session('linkedin_oauth_return_to'))->toBe(route('posts.edit', $post));
});

test('wire navigate request for editable post with expired linkedin returns client redirect page', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->linkedinExpired()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_for' => now()->addDay(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Scheduled LinkedIn post',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($user)
        ->withHeader('X-Livewire-Navigate', '1')
        ->get(route('posts.edit', $post))
        ->assertOk()
        ->assertSee('window.location.replace', false)
        ->assertSee('social-accounts\/connect\/linkedin\/redirect', false);

    expect(session('linkedin_oauth_return_to'))->toBe(route('posts.edit', $post));
});

test('opening queued post with expired linkedin target does not redirect', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->linkedinExpired()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Queued,
        'scheduled_for' => now()->addDay(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'Queued LinkedIn post',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post])
        ->assertSee('View Post')
        ->assertNoRedirect();
});

test('saving editable post with linkedin token expiring before new schedule redirects to linkedin oauth', function () {
    $user = User::factory()->create();
    $account = SocialAccount::factory()->linkedin()->create([
        'user_id' => $user->id,
        'credentials_encrypted' => [
            'access_token' => 'linkedin-access-token',
            'refresh_token' => null,
            'expires_at' => now()->addDays(10)->toIso8601String(),
        ],
    ]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_for' => now()->addWeek(),
    ]);

    PostVariant::factory()->create([
        'post_id' => $post->id,
        'scope_type' => ScopeType::Default,
        'body_text' => 'LinkedIn post',
    ]);

    PostTarget::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post])
        ->set('scheduled_for', now()->addDays(20)->format('Y-m-d\TH:i'))
        ->call('save', 'schedule')
        ->assertRedirect(route('social-accounts.linkedin-oauth-redirect'));

    expect(session('message'))->toContain('scheduled successfully');
    expect(session('linkedin_oauth_return_to'))->toBe(route('posts.edit', $post));
});
