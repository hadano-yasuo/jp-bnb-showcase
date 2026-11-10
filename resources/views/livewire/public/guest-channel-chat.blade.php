@php
    $brand = __('pages.brand');
    $labels = [
        'title' => __('pages.chat.title_template', ['brand' => $brand]),
        'reserve' => __('pages.chat.reserve'),
        'placeholder' => __('pages.chat.placeholder'),
        'send' => __('pages.chat.send'),
        'you' => __('pages.chat.you'),
        'host' => $brand,
    ];
@endphp
<div class="py-8 sm:py-12" style="background-color: var(--color-warm-50); min-height: calc(100vh - 4rem);">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">
        <div class="rounded-xl bg-white ring-1 ring-stone-200 shadow-sm p-6 sm:p-8">
            {{-- Header --}}
            <div class="flex justify-between items-start mb-5 pb-5 border-b border-stone-200 gap-4">
                <div class="min-w-0">
                    <p class="text-[10px] tracking-[0.3em] uppercase text-stone-500 mb-1">CHAT</p>
                    <p class="font-serif-jp text-lg sm:text-xl text-stone-900 truncate">{{ $labels['title'] }}</p>
                </div>
                <a href="{{ $reserveUrl }}"
                    class="inline-flex items-center gap-2 px-4 py-2 text-[10px] tracking-[0.2em] uppercase bg-jp-red text-stone-50 hover:bg-jp-red-hover transition-colors rounded whitespace-nowrap">
                    <span>{{ $labels['reserve'] }}</span>
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
            </div>

            {{-- Messages --}}
            <div wire:poll.15s class="space-y-2 max-h-[60vh] overflow-y-auto py-4 px-1 sm:px-2">
                @foreach($messages as $msg)
                    <div @class([
                        'flex',
                        'justify-end' => $msg->isFromGuest(),
                        'justify-start' => $msg->isFromHost(),
                        'justify-center' => ! $msg->isFromGuest() && ! $msg->isFromHost(),
                    ])>
                        <div @class([
                            'max-w-[75%] px-3 py-2 rounded-lg text-sm',
                            'bg-stone-100 text-stone-900' => $msg->isFromGuest(),
                            'text-stone-900 ring-1 ring-stone-200' => $msg->isFromHost(),
                            'italic text-xs text-stone-600 px-3 py-2' => ! $msg->isFromGuest() && ! $msg->isFromHost(),
                        ])
                        @if($msg->isFromHost()) style="background-color: var(--color-warm-50);"
                        @elseif(! $msg->isFromGuest()) style="background-color: var(--color-warm-100);"
                        @endif>
                            <div @class([
                                'text-[10px] tracking-widest uppercase text-stone-500',
                                'hidden' => ! $msg->isFromGuest() && ! $msg->isFromHost(),
                            ])>{{ $msg->isFromGuest() ? $labels['you'] : $labels['host'] }} <span class="ml-1 opacity-70">{{ $msg->sent_at->format('n/j H:i') }}</span></div>
                            <div class="whitespace-pre-wrap break-words leading-relaxed">{!! $msg->renderedMessage() !!}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Input (予約前/予約後ともに常時表示) --}}
            <form wire:submit="sendMessage" class="mt-8 pt-8 border-t border-stone-200">
                <textarea
                    wire:model="body"
                    rows="3"
                    maxlength="5000"
                    placeholder="{{ $labels['placeholder'] }}"
                    class="w-full rounded-lg border border-stone-300 px-4 py-3 text-stone-900 focus:outline-none focus:border-jp-red focus:ring-1 focus:ring-jp-red resize-none leading-relaxed"
                ></textarea>
                @error('body')<p class="mt-2 text-xs text-jp-red">{{ $message }}</p>@enderror
                <div class="mt-3 flex justify-end">
                    <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-3 text-xs tracking-widest uppercase bg-jp-red text-stone-50 hover:bg-jp-red-hover transition-colors rounded">
                        {{ $labels['send'] }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
