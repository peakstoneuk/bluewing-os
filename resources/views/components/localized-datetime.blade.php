@props([
    'datetime' => null,
    'format' => 'M j, Y g:i A',
    'empty' => 'No date',
])

@if ($datetime)
    {{ auth()->user()->formatDateTime($datetime, $format) }}
@else
    {{ $empty }}
@endif
