@props([
    'title' => null,
    'description' => null,
])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ app()->getLocale() === 'ja' ? 'font-sans-jp' : 'font-sans-en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>{{ $title ? $title.' | '.__('pages.brand') : __('pages.brand') }}</title>
    @if($description)
        <meta name="description" content="{{ $description }}">
    @endif

    @stack('meta')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 text-stone-800 antialiased">
    <x-nav.header />

    <main>
        {{ $slot }}
    </main>

    <x-nav.footer />

    @stack('scripts')
</body>
</html>
