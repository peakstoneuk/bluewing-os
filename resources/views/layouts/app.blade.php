<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-flash-messages class="mb-6" />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
