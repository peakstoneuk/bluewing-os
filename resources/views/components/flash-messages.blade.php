@props([
    'message' => null,
    'type' => null,
])

@php
    $items = $message !== null
        ? collect([['text' => $message, 'type' => $type ?? 'success']])
        : collect([
            ['text' => session('message'), 'type' => 'success'],
            ['text' => session('warning'), 'type' => 'warning'],
            ['text' => session('error'), 'type' => 'error'],
        ]);

    $items = $items->filter(fn (array $item) => filled($item['text']));
@endphp

@if ($items->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'space-y-3']) }}>
        @foreach ($items as $item)
            @php
                $classes = match ($item['type']) {
                    'warning' => 'bg-warning-50 text-warning-800 dark:bg-warning-900/20 dark:text-warning-200',
                    'error' => 'bg-danger-50 text-danger-700',
                    default => 'bg-success-50 text-success-700',
                };
            @endphp

            <div class="rounded-lg p-4 {{ $classes }}" role="status">
                {{ $item['text'] }}
            </div>
        @endforeach
    </div>
@endif
