<div>
    <flux:heading size="xl">{{ __('Social Accounts') }}</flux:heading>
    <flux:subheading>{{ __('Manage your connected social media accounts.') }}</flux:subheading>

    <div class="mt-6 flex flex-wrap gap-3">
        <flux:button variant="primary" :href="route('social-accounts.connect-x')" wire:navigate>
            {{ __('Connect X Account') }}
        </flux:button>
        <flux:button variant="primary" :href="route('social-accounts.connect-linkedin')" wire:navigate>
            {{ __('Connect LinkedIn Account') }}
        </flux:button>
        <flux:button variant="primary" :href="route('social-accounts.connect-bluesky')" wire:navigate>
            {{ __('Connect Bluesky Account') }}
        </flux:button>
    </div>

    <div class="mt-8 space-y-4">
        @forelse ($accounts as $account)
            @php
                $linkedInStatus = $linkedInStatuses[$account->id] ?? null;
            @endphp
            <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-center gap-4">
                    <div class="flex size-10 items-center justify-center rounded-lg {{ $account->provider->value === 'x' ? 'bg-zinc-900 dark:bg-white' : ($account->provider->value === 'linkedin' ? 'bg-[#0A66C2]' : 'bg-blue-500') }}">
                        @if ($account->provider->value === 'x')
                            <span class="text-sm font-bold text-white dark:text-zinc-900">𝕏</span>
                        @elseif ($account->provider->value === 'linkedin')
                            <span class="inline-flex size-5 items-center justify-center rounded-sm bg-white text-xs font-bold text-[#0A66C2]">in</span>
                        @else
                            <span class="text-sm font-bold text-white">🦋</span>
                        @endif
                    </div>
                    <div>
                        <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $account->display_name }}</div>
                        <div class="text-sm text-zinc-500">
                            {{ $account->provider->label() }}
                            @if ($account->user_id !== auth()->id())
                                <span class="ml-2 rounded-full bg-primary-100 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-900 dark:text-primary-200">
                                    {{ __('Shared') }}
                                </span>
                            @endif
                        </div>
                        @if ($linkedInStatus)
                            @if ($linkedInStatus['message'])
                                <p @class([
                                    'mt-1 text-xs',
                                    'text-danger-700 dark:text-danger-300' => $linkedInStatus['needs_attention'],
                                    'text-warning-700 dark:text-warning-300' => ! $linkedInStatus['needs_attention'],
                                ])>{{ $linkedInStatus['message'] }}</p>
                            @endif
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @if ($account->user_id === auth()->id())
                        @if ($account->provider->value === 'linkedin')
                            <flux:button
                                size="sm"
                                :variant="$linkedInStatus['needs_attention'] ?? false ? 'primary' : 'subtle'"
                                wire:click="reauthorizeLinkedIn({{ $account->id }})"
                            >
                                {{ __('Reauthorise') }}
                            </flux:button>
                        @endif
                        <flux:button size="sm" :href="route('social-accounts.permissions', $account)" wire:navigate>
                            {{ __('Permissions') }}
                        </flux:button>
                        <flux:button size="sm" variant="danger" wire:click="deleteSocialAccount({{ $account->id }})" wire:confirm="{{ __('Are you sure you want to disconnect this account?') }}">
                            {{ __('Disconnect') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
                <flux:text>{{ __('No social accounts connected yet. Connect one above to get started.') }}</flux:text>
            </div>
        @endforelse
    </div>
</div>
