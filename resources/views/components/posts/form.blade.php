@props([
    'submitLabel' => 'Save',
    'accounts',
    'providers',
    'editable' => true,
    'scheduledFor' => '',
    'scheduledAt' => null,
    'bodyText' => '',
    'providerOverrides' => [],
    'accountOverrides' => [],
    'selectedAccounts' => [],
])

<div class="max-w-3xl space-y-8">
    @error('credentials')
        <div class="rounded-lg bg-danger-50 p-4 text-danger-700">{{ $message }}</div>
    @enderror

    {{-- Schedule --}}
    <div>
        @if ($editable)
            <flux:input
                wire:model="scheduled_for"
                :label="__('Scheduled For') . ' (' . auth()->user()->timezoneLabel() . ')'"
                type="datetime-local"
                required
            />
        @else
            <flux:heading size="sm" class="mb-1">{{ __('Scheduled For') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-400">
                <x-localized-datetime :datetime="$scheduledAt" empty="—" />
            </flux:text>
        @endif
    </div>

    {{-- Target Accounts --}}
    <div>
        <flux:heading size="sm" class="mb-3">{{ __('Target Accounts') }}</flux:heading>

        @if ($accounts->isEmpty())
            <flux:text>{{ __('No accounts available. Connect a social account first.') }}</flux:text>
        @elseif ($editable)
            <div class="space-y-2">
                @foreach ($accounts as $account)
                    <label class="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                        <input
                            type="checkbox"
                            wire:model.live="selected_accounts"
                            value="{{ $account->id }}"
                            class="rounded border-zinc-300 text-primary-500 focus:ring-primary-500"
                        />
                        <div class="flex items-center gap-2">
                            <span class="inline-flex size-6 items-center justify-center rounded {{ $account->provider->value === 'x' ? 'bg-zinc-900 dark:bg-white' : ($account->provider->value === 'linkedin' ? 'bg-[#0A66C2]' : 'bg-blue-500') }}">
                                @if ($account->provider->value === 'x')
                                    <span class="text-xs font-bold text-white dark:text-zinc-900">𝕏</span>
                                @elseif ($account->provider->value === 'linkedin')
                                    <span class="inline-flex size-4 items-center justify-center rounded-[2px] bg-white text-[10px] font-bold text-[#0A66C2]">in</span>
                                @else
                                    <span class="text-xs text-white">🦋</span>
                                @endif
                            </span>
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $account->display_name }}</span>
                            <span class="text-xs text-zinc-500">{{ $account->provider->label() }}</span>
                        </div>
                    </label>
                @endforeach
            </div>
        @else
            <div class="space-y-2">
                @foreach ($accounts->whereIn('id', $selectedAccounts) as $account)
                    <div class="flex items-center gap-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <span class="inline-flex size-6 items-center justify-center rounded {{ $account->provider->value === 'x' ? 'bg-zinc-900 dark:bg-white' : ($account->provider->value === 'linkedin' ? 'bg-[#0A66C2]' : 'bg-blue-500') }}">
                            @if ($account->provider->value === 'x')
                                <span class="text-xs font-bold text-white dark:text-zinc-900">𝕏</span>
                            @elseif ($account->provider->value === 'linkedin')
                                <span class="inline-flex size-4 items-center justify-center rounded-[2px] bg-white text-[10px] font-bold text-[#0A66C2]">in</span>
                            @else
                                <span class="text-xs text-white">🦋</span>
                            @endif
                        </span>
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $account->display_name }}</span>
                        <span class="text-xs text-zinc-500">{{ $account->provider->label() }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        @error('selected_accounts')
            <flux:text class="mt-2 text-danger-600">{{ $message }}</flux:text>
        @enderror

        @if ($editable && count($this->linkedInAuthorizationWarnings) > 0)
            <div class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-4 dark:border-warning-700 dark:bg-warning-900/20">
                <p class="text-sm font-medium text-warning-800 dark:text-warning-200">{{ __('LinkedIn authorization required') }}</p>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-warning-700 dark:text-warning-300">
                    @foreach ($this->linkedInAuthorizationWarnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
                <p class="mt-2 text-sm text-warning-700 dark:text-warning-300">
                    {{ __('If you save this post with an expired or expiring LinkedIn connection, you will be redirected to reconnect automatically.') }}
                </p>
            </div>
        @endif
    </div>

    {{-- Default Text --}}
    <div>
        <flux:heading size="sm" class="mb-1">{{ __('Post Content') }}</flux:heading>
        @if ($editable)
            <flux:subheading class="mb-3">{{ __('This text is used for all selected accounts unless overridden below.') }}</flux:subheading>
            <flux:textarea
                wire:model="body_text"
                :label="__('Default Text')"
                rows="4"
                required
            />
        @else
            <div class="rounded-lg border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-800/30">
                <p class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $bodyText ?: '—' }}</p>
            </div>
        @endif
        @error('body_text')
            <flux:text class="mt-1 text-danger-600">{{ $message }}</flux:text>
        @enderror
    </div>

    {{-- Media Uploader / Viewer --}}
    <x-posts.media-uploader
        :media-items="$this->mediaItems"
        :has-bluesky-target="$this->hasBlueskyTarget"
        :editable="$editable"
    />

    @if ($editable)
        {{-- Media Limits Reference --}}
        <x-posts.media-limits />
    @endif

    {{-- Provider Overrides --}}
    @if (count($providers) > 0)
        <div x-data="{ open: @js(collect($providerOverrides)->filter(fn ($v) => trim($v ?? '') !== '')->isNotEmpty()) }">
            <button
                type="button"
                x-on:click="open = !open"
                class="flex w-full items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 text-left transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
                <div>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Provider Overrides') }}</span>
                    <span class="ml-2 text-xs text-zinc-500">{{ __('Override text per platform') }}</span>
                </div>
                <svg
                    class="size-5 text-zinc-400 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            <div x-show="open" x-collapse x-cloak class="mt-3 space-y-4">
                @foreach ($providers as $provider)
                    @if ($editable)
                        <flux:textarea
                            wire:model="provider_overrides.{{ $provider->value }}"
                            :label="$provider->label() . ' Override'"
                            :placeholder="__('Leave blank to use default text')"
                            rows="3"
                        />
                        @error('provider_overrides.' . $provider->value)
                            <flux:text class="mt-1 text-danger-600">{{ $message }}</flux:text>
                        @enderror
                    @else
                        @php $override = $providerOverrides[$provider->value] ?? ''; @endphp
                        @if (trim($override) !== '')
                            <div>
                                <flux:heading size="xs" class="mb-1">{{ $provider->label() }} {{ __('Override') }}</flux:heading>
                                <div class="rounded-lg border border-zinc-200 bg-zinc-50/50 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                                    <p class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $override }}</p>
                                </div>
                            </div>
                        @endif
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Per-Account Overrides --}}
    @if (count($selectedAccounts) > 1)
        <div x-data="{ open: @js(collect($accountOverrides)->filter(fn ($v) => trim($v ?? '') !== '')->isNotEmpty()) }">
            <button
                type="button"
                x-on:click="open = !open"
                class="flex w-full items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 text-left transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
            >
                <div>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Per-Account Overrides') }}</span>
                    <span class="ml-2 text-xs text-zinc-500">{{ __('Override text per account') }}</span>
                </div>
                <svg
                    class="size-5 text-zinc-400 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            <div x-show="open" x-collapse x-cloak class="mt-3 space-y-4">
                @foreach ($accounts->whereIn('id', $selectedAccounts) as $account)
                    @if ($editable)
                        <flux:textarea
                            wire:model="account_overrides.{{ $account->id }}"
                            :label="$account->display_name . ' (' . $account->provider->label() . ')'"
                            :placeholder="__('Leave blank to use default text')"
                            rows="3"
                        />
                        @error('account_overrides.' . $account->id)
                            <flux:text class="mt-1 text-danger-600">{{ $message }}</flux:text>
                        @enderror
                    @else
                        @php $override = $accountOverrides[$account->id] ?? ''; @endphp
                        @if (trim($override) !== '')
                            <div>
                                <flux:heading size="xs" class="mb-1">{{ $account->display_name }} ({{ $account->provider->label() }})</flux:heading>
                                <div class="rounded-lg border border-zinc-200 bg-zinc-50/50 p-3 dark:border-zinc-700 dark:bg-zinc-800/30">
                                    <p class="whitespace-pre-wrap text-sm text-zinc-700 dark:text-zinc-300">{{ $override }}</p>
                                </div>
                            </div>
                        @endif
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    {{-- Actions --}}
    <div class="flex items-center gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
        @if ($editable)
            <flux:button variant="primary" wire:click="save('schedule')">
                {{ __('Schedule') }}
            </flux:button>
            <flux:button wire:click="save('draft')">
                {{ __('Save as Draft') }}
            </flux:button>
        @endif
        <flux:button :href="route('dashboard')" wire:navigate>
            {{ $editable ? __('Cancel') : __('Back to dashboard') }}
        </flux:button>
    </div>
</div>
