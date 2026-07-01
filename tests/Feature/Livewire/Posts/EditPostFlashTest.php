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

test('edit post save shows success message without redirecting', function () {
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

    $component = Livewire::actingAs($user)
        ->test(EditPost::class, ['post' => $post])
        ->set('body_text', 'Updated text')
        ->set('scheduled_for', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->call('save', 'draft')
        ->assertHasNoErrors()
        ->assertSet('flashMessage', 'Post saved as draft successfully.')
        ->assertNoRedirect()
        ->assertSee('Post saved as draft successfully.');

    expect(substr_count($component->html(), 'Post saved as draft successfully.'))->toBe(1);
});
