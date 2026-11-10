@props([
    'src', // R2 内の相対パス（例: 'rooms/building-a/2f/main.jpg'）
    'alt' => '',
    'loading' => 'lazy', // lazy | eager
])

<img
    src="{{ image_url($src) }}"
    alt="{{ $alt }}"
    loading="{{ $loading }}"
    {{ $attributes }}
/>
