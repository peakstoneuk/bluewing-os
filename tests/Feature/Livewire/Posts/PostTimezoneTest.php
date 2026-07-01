<?php

use App\Enums\PostStatus;
use App\Enums\ScopeType;
use App\Livewire\Posts\CreatePost;
use App\Livewire\Posts\EditPost;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('create post stores scheduled time converted from user timezone to utc', function () {
    Date::setTestNow(Date::parse('2026-07-01 12:00:00', 'UTC'));

    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $account = SocialAccount::factory()->x()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(CreatePost::class)
        ->set('body_text', 'Timezone post')
        ->set('scheduled_for', '2026-07-02T14:00')
        ->set('selected_accounts', [$account->id])
        ->call('save', 'schedule')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $post = Post::where('user_id', $user->id)->first();

    expect($post)->not->toBeNull();
    expect($post->scheduled_for?->toDateTimeString())->toBe('2026-07-02 18:00:00');
});

test('edit post loads and saves scheduled time in user timezone', function () {
    Date::setTestNow(Date::parse('2026-07-01 12:00:00', 'UTC'));

    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $account = SocialAccount::factory()->x()->create(['user_id' => $user->id]);

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'scheduled_for' => Date::parse('2026-07-02 18:00:00', 'UTC'),
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

    Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post])
        ->assertSet('scheduled_for', '2026-07-02T14:00')
        ->set('scheduled_for', '2026-07-03T09:30')
        ->call('save', 'draft')
        ->assertHasNoErrors()
        ->assertSet('flashMessage', 'Post saved as draft successfully.');

    expect($post->fresh()->scheduled_for?->toDateTimeString())->toBe('2026-07-03 13:30:00');
});
