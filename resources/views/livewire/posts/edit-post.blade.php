<div>
    <flux:heading size="xl">{{ $this->canEdit ? __('Edit Post') : __('View Post') }}</flux:heading>

    @if (! $this->canEdit)
        <div class="mt-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50" role="alert">
            <p class="font-medium text-zinc-900 dark:text-zinc-100">
                {{ __('This post can no longer be edited.') }}
            </p>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('It has already been queued or published. You can view the content, targets, and media below.') }}
            </p>
        </div>
    @else
        <flux:subheading class="mb-6">{{ __('Update your scheduled post.') }}</flux:subheading>
    @endif

    <div class="mt-6">
        <x-posts.form
            :accounts="$this->accounts"
            :providers="$this->providers"
            :editable="$this->canEdit"
            :scheduled-for="$this->scheduled_for"
            :body-text="$this->body_text"
            :provider-overrides="$this->provider_overrides"
            :account-overrides="$this->account_overrides"
            :selected-accounts="$this->selected_accounts"
        />
    </div>
</div>
