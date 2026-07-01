<div>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ __('Your scheduled and published posts.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" :href="route('posts.create')" wire:navigate>
            {{ __('Create Post') }}
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="mt-6 flex flex-wrap gap-3">

    {{-- Posts List --}}
    <div class="mt-6 space-y-3">
        @forelse ($posts as $post)
            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <x-posts.status-badge :status="$post->status" />
                            <span class="text-sm text-zinc-500">
                                <x-localized-datetime :datetime="$post->scheduled_for" />
                            </span>
                        </div>
                        <p class="truncate text-zinc-900 dark:text-zinc-100">
                            {{ $post->variants->where('scope_type', \App\Enums\ScopeType::Default)->first()?->body_text ?? '(no default text)' }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($post->targets as $target)
                                <span class="inline-flex items-center gap-1 rounded-full border border-zinc-200 px-2 py-0.5 text-xs dark:border-zinc-700">
                                    @if ($target->socialAccount->provider->value === 'x')
                                        <span class="font-bold">𝕏</span>
                                    @elseif ($target->socialAccount->provider->value === 'linkedin')
                                        <span class="inline-flex size-3.5 items-center justify-center rounded-[1px] bg-[#0A66C2] text-[8px] font-bold text-white">in</span>
                                    @elseif ($target->socialAccount->provider->value === 'bluesky')
                                        <span>🦋</span>
                                    @else
                                        <span class="text-zinc-400" title="{{ __('Unknown provider') }}">?</span>
                                    @endif
                                    {{ $target->socialAccount->display_name }}
                                    @if ($target->status === \App\Enums\PostTargetStatus::Sent)
                                        <span class="text-success-600">✓</span>
                                    @elseif ($target->status === \App\Enums\PostTargetStatus::Failed)
                                        <span class="text-danger-600">✗</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @if (in_array($post->status, [\App\Enums\PostStatus::Draft, \App\Enums\PostStatus::Scheduled]))
                            <flux:button size="sm" :href="route('posts.edit', $post)" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button size="sm" wire:click="cancelPost({{ $post->id }})" wire:confirm="{{ __('Cancel this post?') }}">
                                {{ __('Cancel') }}
                            </flux:button>
                        @elseif (in_array($post->status, [\App\Enums\PostStatus::Queued, \App\Enums\PostStatus::Publishing, \App\Enums\PostStatus::Sent, \App\Enums\PostStatus::Failed]))
                            <flux:button size="sm" variant="subtle" :href="route('posts.edit', $post)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                        @endif
                        @if ($post->user_id === auth()->id())
                            <flux:button size="sm" variant="danger" wire:click="deletePost({{ $post->id }})" wire:confirm="{{ __('Delete this post permanently?') }}">
                                {{ __('Delete') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                <flux:text>{{ __('No posts yet. Create your first post to get started.') }}</flux:text>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $posts->links() }}
    </div>
</div>
