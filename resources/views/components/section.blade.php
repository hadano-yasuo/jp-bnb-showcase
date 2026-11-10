@props([
    'as' => 'section',
    'bg' => 'default', // default | alt (交互配置用)
])

<{{ $as }} @class([
    'py-16 md:py-24 px-4 sm:px-6 lg:px-8',
    'bg-stone-50' => $bg === 'default',
    'bg-stone-100' => $bg === 'alt',
])>
    <div class="max-w-7xl mx-auto">
        {{ $slot }}
    </div>
</{{ $as }}>
