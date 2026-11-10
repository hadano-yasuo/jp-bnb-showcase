@props([
    'level' => 2,
    'align' => 'left', // left | center
])

@php
    $tag = 'h'.max(1, min(6, (int) $level));
    $sizes = [
        1 => 'text-5xl md:text-7xl',
        2 => 'text-3xl md:text-4xl',
        3 => 'text-xl md:text-2xl',
    ];
    $size = $sizes[(int) $level] ?? 'text-lg';
    $font = app()->getLocale() === 'ja' ? 'font-serif-jp' : 'font-serif-en';
@endphp

<{{ $tag }} @class([
    $size,
    $font,
    'tracking-wide text-stone-900',
    'text-center' => $align === 'center',
])>
    {{ $slot }}
</{{ $tag }}>
