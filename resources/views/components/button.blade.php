@props([
    'variant' => 'primary', // primary | secondary | ghost
    'href' => null,
    'type' => 'button',
])

@php
    $base = 'inline-flex items-center justify-center px-6 py-3 text-sm md:text-base font-medium tracking-wide rounded-none transition-all duration-200';
    $variants = [
        'primary' => 'bg-jp-red text-white hover:bg-jp-red-hover',
        'secondary' => 'bg-stone-900 text-stone-50 hover:bg-stone-800',
        'ghost' => 'border border-stone-800 text-stone-800 hover:bg-stone-900 hover:text-stone-50',
    ];
    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
