<x-filament-panels::page>
    @php
        $messages = $this->messages;
        $drafts = $this->drafts;
        // 返信フォームは常に表示 (直接予約/OTA/メッセージ有無で分岐しない)。
        // 送信時に doSend() が予約種別に応じて適切な経路 (email or beds24) を選ぶ。
        $canReply = true;
        $isUnreplied = $this->isUnreplied();
        $isMarkedNoReplyNeeded = $this->isMarkedNoReplyNeeded();
        $booking = $this->booking;
        $listing = $this->listing;
        $guestName = null;
        if ($booking !== null) {
            $guestName = trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''));
            if ($guestName === '') {
                $guestName = null;
            }
        }
        $listingName = $listing?->display_name ?? $listing?->name;
        $channelName = $booking !== null && ! empty($booking->channel) ? ucfirst($booking->channel) : null;
        $stayRange = null;
        if ($booking !== null && $booking->arrival !== null && $booking->departure !== null) {
            $stayRange = $booking->arrival->format('n/j').' - '.$booking->departure->format('n/j');
        }
    @endphp

    {{-- ヘッダーカード --}}
    <div class="mb-4 rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 sm:p-8">
        <div class="flex justify-between items-start gap-4">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="text-xl font-semibold text-gray-900 dark:text-white truncate">
                        {{ $guestName ?? '(名前未取得)' }}
                    </div>
                    @if($listingName !== null)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-100 dark:bg-blue-900/30 px-3 py-1.5 text-sm text-blue-700 dark:text-blue-300">
                            <x-heroicon-o-home class="w-4 h-4" />
                            リスティング: {{ $listingName }}
                        </span>
                    @endif
                </div>
                @if($booking !== null && ! empty($booking->email))
                    <a href="mailto:{{ $booking->email }}" class="text-base text-gray-500 dark:text-gray-400 hover:text-primary-600 dark:hover:text-primary-400">
                        {{ $booking->email }}
                    </a>
                @endif
                <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
                    @if($stayRange !== null)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-gray-700 dark:text-gray-300">
                            <x-heroicon-o-calendar-days class="w-4 h-4" />
                            {{ $stayRange }}
                        </span>
                    @endif
                    @if($channelName !== null)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-gray-700 dark:text-gray-300">
                            <x-heroicon-o-globe-alt class="w-4 h-4" />
                            {{ $channelName }}
                        </span>
                    @endif
                    @if($booking !== null && ($booking->num_adult !== null || $booking->num_child !== null))
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 dark:bg-gray-800 px-3 py-1.5 text-gray-700 dark:text-gray-300">
                            <x-heroicon-o-user-group class="w-4 h-4" />
                            大人{{ (int) ($booking->num_adult ?? 0) }}名
                            @if((int) ($booking->num_child ?? 0) > 0)
                                / 子{{ (int) $booking->num_child }}名
                            @endif
                        </span>
                    @endif
                    @if($isMarkedNoReplyNeeded)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-400 text-white px-3 py-1.5 text-sm">
                            <x-heroicon-o-check class="w-4 h-4" />
                            返信不要
                        </span>
                    @elseif($isUnreplied)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 dark:bg-amber-900/30 px-3 py-1.5 text-sm text-amber-800 dark:text-amber-200">
                            <x-heroicon-o-exclamation-circle class="w-4 h-4" />
                            要返信
                        </span>
                    @endif
                </div>
            </div>
            @if($booking !== null)
                <a href="https://beds24.com/control2.php?function=viewbooking&bookid={{ $booking->beds24_booking_id }}" target="_blank"
                    class="inline-flex items-center gap-2 rounded-lg bg-gray-50 dark:bg-gray-800 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 whitespace-nowrap">
                    <x-heroicon-o-arrow-top-right-on-square class="w-5 h-5" />
                    beds24
                </a>
            @endif
        </div>
    </div>

    {{-- スレッド --}}
    <div
        x-data="{}"
        x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight })"
        x-on:conversation-updated.window="$nextTick(() => { setTimeout(() => { $el.scrollTop = $el.scrollHeight }, 100) })"
        class="rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950 p-4 space-y-3 overflow-y-auto"
        style="max-height: 60vh; min-height: 400px;"
    >
        @php $prevDate = null; @endphp
        @foreach($messages as $msg)
            @php
                $isGuest = $msg->source === \App\Models\Message::SOURCE_GUEST;
                $isHost = $msg->source === \App\Models\Message::SOURCE_HOST;
                $isSystem = $msg->source === \App\Models\Message::SOURCE_SYSTEM;
                $ja = $msg->japaneseTranslation();
                $currentDate = $msg->sent_at?->format('Y-m-d');
                $showDateDivider = $currentDate !== null && $currentDate !== $prevDate;
                if ($showDateDivider) { $prevDate = $currentDate; }
            @endphp

            @if($showDateDivider)
                <div class="flex justify-center py-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-full px-3 py-0.5">
                        {{ $msg->sent_at->format('Y年n月j日') }}
                    </span>
                </div>
            @endif

            @if($isSystem)
                @if($msg->isEmailSendLog())
                    {{-- メール送信ログ: 中央、詳細表示 (件名 + 宛先 + 本文プレビュー) --}}
                    <div class="flex justify-center">
                        <div class="max-w-[80%] w-full text-xs text-gray-600 dark:text-gray-300 bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800/60 rounded-lg px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-medium">📧 メール送信 → <span class="text-amber-800 dark:text-amber-200">{{ $msg->emailed_to }}</span></span>
                                <span class="opacity-70">{{ $msg->sent_at->format('H:i') }}</span>
                            </div>
                            <div class="mt-1 text-gray-700 dark:text-gray-200">
                                <span class="font-medium">件名:</span> {{ $msg->email_subject }}
                            </div>
                            <div class="mt-1 text-gray-600 dark:text-gray-300 whitespace-pre-wrap text-left leading-relaxed">
                                {{ \Illuminate\Support\Str::limit($msg->message, 300) }}
                            </div>
                        </div>
                    </div>
                @else
                    {{-- 通常システム: 中央、控えめ --}}
                    <div class="flex justify-center">
                        <div class="max-w-[70%] text-center text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-800 rounded-full px-3 py-1">
                            <span class="font-medium">[system]</span>
                            {{ $msg->message }}
                            <span class="opacity-70">· {{ $msg->sent_at->format('H:i') }}</span>
                        </div>
                    </div>
                @endif
            @elseif($isHost)
                {{-- ホスト（自分）: 右寄せ --}}
                <div class="flex justify-end">
                    <div class="max-w-[75%]">
                        <div class="bg-emerald-500 text-white rounded-2xl rounded-tr-sm px-4 py-2 shadow-sm">
                            <div class="whitespace-pre-wrap text-base text-left">{!! $msg->renderedMessage() !!}</div>
                            @if($ja !== null)
                                <div class="mt-1 pt-1 border-t border-emerald-400/50 text-xs text-emerald-50/90 text-left">
                                    <div class="flex justify-start"><span class="px-1.5 py-0 rounded bg-emerald-700/40 text-[10px] font-medium">自動翻訳</span></div>
                                    <div class="whitespace-pre-wrap mt-0.5">{{ $ja }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 text-right mt-1">
                            {{ $msg->sent_at->format('H:i') }}
                            @if($msg->detected_language !== null && $msg->detected_language !== 'ja')
                                <span class="ml-1 opacity-70">· {{ strtoupper($msg->detected_language) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                {{-- ゲスト: 左寄せ --}}
                <div class="flex justify-start">
                    <div class="max-w-[75%]">
                        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl rounded-tl-sm px-4 py-2 shadow-sm">
                            <div class="whitespace-pre-wrap text-base text-gray-950 dark:text-white text-left">{!! $msg->renderedMessage() !!}</div>
                            @if($ja !== null)
                                <div class="mt-1 pt-1 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400 text-left">
                                    <div class="flex justify-start"><span class="px-1.5 py-0 rounded bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-[10px] font-medium">自動翻訳</span></div>
                                    <div class="whitespace-pre-wrap mt-0.5">{{ $ja }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ $msg->sent_at->format('H:i') }}
                            @if($msg->detected_language !== null && $msg->detected_language !== 'ja')
                                <span class="ml-1 opacity-70">· {{ strtoupper($msg->detected_language) }}</span>
                            @endif
                            @if($this->shouldShowUnread($msg))
                                <span class="ml-1 text-amber-600 dark:text-amber-400">·未読</span>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    {{-- 更新 / 返信不要ボタン（スレッド下、中央） --}}
    <div class="flex justify-center items-center gap-2 mt-3">
        <x-filament::button
            wire:click="syncMessages"
            wire:loading.attr="disabled"
            icon="heroicon-o-arrow-path"
            color="gray"
            outlined
            size="sm"
        >
            更新
        </x-filament::button>

        @if($isUnreplied)
            <x-filament::button
                wire:click="markNoReplyNeeded"
                icon="heroicon-o-check"
                color="gray"
                outlined
                size="sm"
            >
                このメッセージは返信不要
            </x-filament::button>
        @elseif($isMarkedNoReplyNeeded)
            <span class="inline-flex items-center h-6 px-3 rounded-full text-xs font-semibold bg-gray-400 text-white">
                返信不要
            </span>
            <x-filament::button
                wire:click="unmarkNoReplyNeeded"
                color="gray"
                outlined
                size="xs"
            >
                取り消し
            </x-filament::button>
        @endif
    </div>

    {{-- 予約単位の自動送信 opt-out --}}
    @php $booking = $this->booking; @endphp
    @if($booking !== null)
        <div class="flex justify-center items-center gap-2 mt-2 text-sm text-gray-600 dark:text-gray-400">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input
                    type="checkbox"
                    wire:click="toggleAutoMessageOptOut"
                    @checked($booking->auto_message_opt_out)
                    class="rounded border-gray-300 dark:border-gray-700 text-primary-600 focus:ring-primary-500"
                />
                <span>この予約には自動送信メッセージを送らない</span>
            </label>
        </div>
    @endif

    {{-- 返信フォーム --}}
    @if($canReply)
        @php
            $needsTranslation = $language !== 'ja';
            $langDisplay = match ($language) {
                'ja' => '日本語',
                'en' => '英語',
                'zh' => '中国語',
                'ko' => '韓国語',
                'es' => 'スペイン語',
                'fr' => 'フランス語',
                'de' => 'ドイツ語',
                'pt' => 'ポルトガル語',
                'th' => 'タイ語',
                'vi' => 'ベトナム語',
                default => $languageLabel ?? 'その他',
            };
        @endphp
        {{-- 音声入力（Web Speech API, ja-JP）: 本文とプロンプト両方で使う共通 Alpine コンポーネント --}}
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('voiceInput', () => ({
                    supported: false,
                    recording: false,
                    recognition: null,
                    baseText: '',
                    finalText: '',
                    init() {
                        const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
                        this.supported = !!SR;
                        if (!this.supported) return;
                        this.recognition = new SR();
                        this.recognition.lang = 'ja-JP';
                        this.recognition.continuous = true;
                        this.recognition.interimResults = true;
                        this.recognition.onresult = (e) => {
                            let final = '';
                            let interim = '';
                            for (let i = 0; i < e.results.length; i++) {
                                if (e.results[i].isFinal) final += e.results[i][0].transcript;
                                else interim += e.results[i][0].transcript;
                            }
                            this.finalText = final;
                            const ta = this.$refs.target;
                            ta.value = this.baseText + final + interim;
                            ta.dispatchEvent(new Event('input', { bubbles: true }));
                        };
                        this.recognition.onend = () => {
                            this.recording = false;
                            this.baseText = this.baseText + this.finalText;
                            this.finalText = '';
                        };
                        this.recognition.onerror = (e) => {
                            this.recording = false;
                            console.warn('speech recognition error', e.error);
                        };
                    },
                    toggle() {
                        if (!this.supported) return;
                        if (this.recording) {
                            this.recognition.stop();
                            return;
                        }
                        const current = this.$refs.target.value || '';
                        this.baseText = current && !/[\s\n]$/.test(current) ? current + ' ' : current;
                        this.finalText = '';
                        try {
                            this.recognition.start();
                            this.recording = true;
                        } catch (err) {
                            console.warn('recognition start failed', err);
                        }
                    },
                }));
            });
        </script>

        <div class="mt-4 space-y-3 rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
            {{-- 本文（日本語入力） --}}
            <div x-data="voiceInput">
                <div class="flex items-center flex-wrap gap-x-4 gap-y-1 mb-1">
                    <label class="text-sm font-medium text-gray-950 dark:text-white">
                        本文（日本語で入力）
                    </label>
                    <button
                        type="button"
                        x-show="supported"
                        x-cloak
                        x-on:click="toggle()"
                        :class="recording ? 'text-white bg-red-500 hover:bg-red-600 animate-pulse' : 'text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700'"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                        :title="recording ? 'タップで停止' : '音声入力（日本語）'"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5">
                            <path d="M8 1a2 2 0 00-2 2v5a2 2 0 104 0V3a2 2 0 00-2-2z" />
                            <path d="M4.5 7.75a.75.75 0 00-1.5 0V8a5 5 0 004.25 4.945V14.25H5.75a.75.75 0 000 1.5h4.5a.75.75 0 000-1.5H8.75v-1.305A5 5 0 0013 8v-.25a.75.75 0 00-1.5 0V8a3.5 3.5 0 01-7 0v-.25z" />
                        </svg>
                        <span x-text="recording ? '録音中... タップで停止' : '音声入力'"></span>
                    </button>
                    @if($this->templates->isNotEmpty() || $this->autoMessageRules->isNotEmpty())
                        <div x-data>
                            <select
                                x-on:change="if($event.target.value){
                                    const [type, id] = $event.target.value.split(':');
                                    if (type === 'tpl') $wire.useTemplate(parseInt(id));
                                    else if (type === 'rule') $wire.useAutoMessageRule(parseInt(id));
                                    $event.target.value = '';
                                }"
                                class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950 text-gray-950 dark:text-white text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500 py-1"
                            >
                                <option value="">定型文を挿入 ▼</option>
                                @if($this->templates->isNotEmpty())
                                    <optgroup label="定型文">
                                        @foreach($this->templates as $tpl)
                                            <option value="tpl:{{ $tpl->id }}">{{ $tpl->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if($this->autoMessageRules->isNotEmpty())
                                    <optgroup label="自動送信">
                                        @foreach($this->autoMessageRules as $rule)
                                            <option value="rule:{{ $rule->id }}">{{ $rule->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                        </div>
                    @endif
                    @if($needsTranslation)
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            送信時に設定言語（{{ $langDisplay }}）で自動翻訳
                        </span>
                    @endif
                </div>
                <textarea
                    x-ref="target"
                    wire:model.live.debounce.500ms="body"
                    rows="4"
                    class="block w-full rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-950 text-gray-950 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 px-3 py-2 leading-relaxed"
                    placeholder="本文を日本語で入力..."
                ></textarea>
            </div>

            {{-- 翻訳プレビュー（ja以外の会話でのみ） --}}
            @if($needsTranslation)
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-950 dark:text-white">
                            送信内容（翻訳）
                        </label>
                        <x-filament::button
                            wire:click="translatePreview"
                            wire:loading.attr="disabled"
                            :disabled="$translating"
                            icon="heroicon-o-language"
                            color="info"
                            size="xs"
                        >
                            翻訳プレビュー
                        </x-filament::button>
                    </div>
                    <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-950 p-3 min-h-[6rem]">
                        @if(trim($translatedBody) !== '')
                            <div class="whitespace-pre-wrap text-sm text-gray-950 dark:text-white text-left">{{ $translatedBody }}</div>
                        @elseif($translating)
                            <div class="text-sm text-gray-500 dark:text-gray-400">翻訳中...</div>
                        @else
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                「翻訳プレビュー」を押すとここに送信内容が表示されます
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- 誤字脱字チェックの警告パネル --}}
            @if(!empty($proofreadIssues))
                <div class="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 p-3 space-y-2">
                    <div class="flex items-center gap-2 text-sm font-medium text-amber-900 dark:text-amber-100">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4"/>
                        誤字脱字・文法の指摘があります
                    </div>
                    <ul class="text-xs text-amber-900 dark:text-amber-100 space-y-1 list-disc list-inside">
                        @foreach($proofreadIssues as $issue)
                            <li>
                                <span class="font-mono bg-amber-100 dark:bg-amber-900/50 px-1 rounded">「{{ $issue['quote'] }}」</span>
                                <span class="opacity-80">{{ $issue['reason'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if(trim($proofreadSuggestion) !== '')
                        <div class="text-xs text-amber-900 dark:text-amber-100">
                            <div class="font-medium mb-1">修正案:</div>
                            <div class="whitespace-pre-wrap bg-white dark:bg-gray-900 border border-amber-200 dark:border-amber-800 rounded p-2 text-gray-950 dark:text-white">{{ $proofreadSuggestion }}</div>
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-2 pt-1">
                        @if(trim($proofreadSuggestion) !== '')
                            <x-filament::button
                                wire:click="applyProofreadSuggestion"
                                icon="heroicon-o-check"
                                color="warning"
                                size="sm"
                            >
                                修正案を採用
                            </x-filament::button>
                        @endif
                        <x-filament::button
                            wire:click="sendAnyway"
                            icon="heroicon-o-paper-airplane"
                            color="gray"
                            outlined
                            size="sm"
                        >
                            このまま送信
                        </x-filament::button>
                        <x-filament::button
                            wire:click="dismissProofread"
                            color="gray"
                            outlined
                            size="sm"
                        >
                            戻って修正
                        </x-filament::button>
                    </div>
                </div>
            @endif

            {{-- Send / Clear / 言語セレクト / メール送信 --}}
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button
                    wire:click="send"
                    wire:target="send"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-paper-airplane"
                    color="primary"
                    wire:confirm="この内容で送信しますか？beds24 経由でゲストへ届きます。"
                >
                    送信
                </x-filament::button>

                <span wire:loading wire:target="send" class="text-sm text-gray-500 dark:text-gray-400">
                    誤字脱字チェック中...
                </span>

                <x-filament::button
                    wire:click="clearBody"
                    icon="heroicon-o-trash"
                    color="gray"
                    outlined
                >
                    クリア
                </x-filament::button>

                <div class="flex items-center gap-1">
                    <label class="text-xs text-gray-500 dark:text-gray-400">送信言語:</label>
                    <select
                        wire:model.live="language"
                        class="rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950 text-gray-950 dark:text-white text-xs shadow-sm focus:border-primary-500 focus:ring-primary-500 py-1"
                    >
                        <option value="ja">日本語</option>
                        <option value="en">英語</option>
                        <option value="zh">中国語</option>
                        <option value="ko">韓国語</option>
                        <option value="es">スペイン語</option>
                        <option value="fr">フランス語</option>
                        <option value="de">ドイツ語</option>
                        <option value="pt">ポルトガル語</option>
                        <option value="th">タイ語</option>
                        <option value="vi">ベトナム語</option>
                        <option value="other">その他</option>
                    </select>
                    @if($language === 'other')
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            ({{ $languageLabel ?? '判定不可' }})
                        </span>
                    @endif
                </div>

                <x-filament::button
                    wire:click="openEmailModal"
                    icon="heroicon-o-envelope"
                    color="gray"
                    outlined
                    size="sm"
                    class="ml-auto"
                >
                    メールで送信
                </x-filament::button>
            </div>

            {{-- AI 支援パネル（常時表示） --}}
            <div class="mt-3 pt-4 border-t border-gray-200 dark:border-gray-700 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-gray-950 dark:text-white inline-flex items-center gap-1">
                        <x-filament::icon icon="heroicon-o-sparkles" class="h-4 w-4"/>
                        AI 支援
                    </span>
                </div>

                {{-- プロンプト --}}
                <div x-data="voiceInput">
                    <div class="flex items-center flex-wrap gap-x-3 gap-y-1 mb-1">
                        <label class="block text-xs text-gray-500 dark:text-gray-400">
                            プロンプト（省略可） - AI に対する指示
                        </label>
                        <button
                            type="button"
                            x-show="supported"
                            x-cloak
                            x-on:click="toggle()"
                            :class="recording ? 'text-white bg-red-500 hover:bg-red-600 animate-pulse' : 'text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700'"
                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium transition-colors"
                            :title="recording ? 'タップで停止' : '音声入力（日本語）'"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="h-3.5 w-3.5">
                                <path d="M8 1a2 2 0 00-2 2v5a2 2 0 104 0V3a2 2 0 00-2-2z" />
                                <path d="M4.5 7.75a.75.75 0 00-1.5 0V8a5 5 0 004.25 4.945V14.25H5.75a.75.75 0 000 1.5h4.5a.75.75 0 000-1.5H8.75v-1.305A5 5 0 0013 8v-.25a.75.75 0 00-1.5 0V8a3.5 3.5 0 01-7 0v-.25z" />
                            </svg>
                            <span x-text="recording ? '録音中... タップで停止' : '音声入力'"></span>
                        </button>
                    </div>
                    <textarea
                        x-ref="target"
                        wire:model="prompt"
                        rows="2"
                        class="block w-full rounded-lg border border-gray-300 dark:border-gray-700 dark:bg-gray-950 text-gray-950 dark:text-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 px-3 py-2 leading-relaxed"
                        placeholder="（省略可、自動生成なら空でOK）"
                    ></textarea>
                </div>

                {{-- 生成ボタン --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::button
                        wire:click="generateAuto"
                        icon="heroicon-o-sparkles"
                        wire:loading.attr="disabled"
                        :disabled="$generating"
                        color="gray"
                    >
                        自動生成
                    </x-filament::button>

                    <x-filament::button
                        wire:click="generateWithPrompt"
                        icon="heroicon-o-cursor-arrow-rays"
                        wire:loading.attr="disabled"
                        :disabled="$generating"
                        color="info"
                    >
                        生成
                    </x-filament::button>

                    <span wire:loading wire:target="generateAuto,generateWithPrompt" class="text-sm text-gray-500 dark:text-gray-400">
                        生成中...
                    </span>
                </div>

                {{-- 履歴 --}}
                @if($drafts->isNotEmpty())
                    <details class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 bg-gray-50 dark:bg-gray-950">
                        <summary class="cursor-pointer text-sm font-medium text-gray-950 dark:text-white">
                            過去の下書き履歴 ({{ $drafts->count() }}件)
                        </summary>
                        <div class="mt-3 space-y-3">
                            @foreach($drafts as $draft)
                                <div class="border-t border-gray-200 dark:border-gray-700 pt-2 first:border-t-0 first:pt-0">
                                    <div class="flex flex-wrap items-start justify-between gap-2 text-xs text-gray-500 dark:text-gray-400 mb-1">
                                        <div class="space-y-0.5">
                                            <div>
                                                {{ $draft->generated_at->format('m/d H:i') }}
                                                · {{ $draft->language }}
                                                · in {{ $draft->input_tokens }}/out {{ $draft->output_tokens }} tok
                                                · ${{ $draft->cost_usd }}
                                            </div>
                                            @if($draft->prompt)
                                                <div>プロンプト: <em>{{ \Illuminate\Support\Str::limit($draft->prompt, 60) }}</em></div>
                                            @endif
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="useDraft({{ $draft->id }})"
                                            class="text-primary-600 hover:underline text-xs whitespace-nowrap"
                                        >
                                            この下書きを使う
                                        </button>
                                    </div>
                                    <div class="whitespace-pre-wrap text-xs text-gray-800 dark:text-gray-200 bg-white dark:bg-gray-900 p-2 rounded border border-gray-200 dark:border-gray-700 text-left">{{ $draft->draft_text }}</div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        </div>
    @else
        <div class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400 py-4">
            返信対象のゲストメッセージがありません（すべて host/system）。
        </div>
    @endif

    {{-- メール送信モーダル (Task #5: URL 剥奪 OTA 対策) --}}
    @if($emailModalOpen)
        <div
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 py-8"
            wire:click.self="closeEmailModal"
        >
            <div class="w-full max-w-2xl rounded-xl bg-white dark:bg-gray-900 shadow-2xl">
                <div class="border-b border-gray-200 dark:border-gray-800 px-6 py-4 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-950 dark:text-white">メールで送信</h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            URL 剥奪 OTA (Agoda 等) 向け。beds24 チャットには投稿されません。
                        </p>
                    </div>
                    <button type="button" wire:click="closeEmailModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">宛先メールアドレス *</label>
                        <input type="email" wire:model="emailTo" maxlength="255"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm px-3 py-2">
                        @error('emailTo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            送信後この予約に保存され、次回モーダルでプレフィルされます。
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">件名 *</label>
                        <input type="text" wire:model="emailSubject" maxlength="255"
                            placeholder="例: 【Sample Stay】ゲスト情報登録のお願い"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm px-3 py-2">
                        @error('emailSubject')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <div class="flex items-end justify-between mb-1">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300">本文 *</label>
                            <div class="flex items-center gap-2">
                                @if($this->templates->isNotEmpty())
                                    <select onchange="if(this.value){ $wire.useTemplateForEmail(parseInt(this.value)); this.value=''; }"
                                        class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-950 text-xs py-1">
                                        <option value="">定型文…</option>
                                        @foreach($this->templates as $tpl)
                                            <option value="{{ $tpl->id }}">{{ $tpl->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if($this->autoMessageRules->isNotEmpty())
                                    <select onchange="if(this.value){ $wire.useAutoMessageRuleForEmail(parseInt(this.value)); this.value=''; }"
                                        class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-950 text-xs py-1">
                                        <option value="">自動送信…</option>
                                        @foreach($this->autoMessageRules as $rule)
                                            <option value="{{ $rule->id }}">{{ $rule->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>
                        </div>
                        <textarea wire:model="emailBody" rows="10" maxlength="10000"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-950 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm leading-relaxed px-3 py-2"></textarea>
                        @error('emailBody')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            通常返信と同じ Blade 変数 (例: <code>@{{ $guest_registration_url }}</code>) が使えます。送信時に展開されます。
                        </p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="emailIncludeNoReplyFooter"
                            class="rounded border-gray-300 dark:border-gray-700 text-primary-600 focus:ring-primary-500">
                        「このメールには返信しないでください」を末尾に自動追加する
                    </label>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-800 px-6 py-4 flex justify-end gap-2">
                    <x-filament::button wire:click="closeEmailModal" color="gray" outlined>キャンセル</x-filament::button>
                    <x-filament::button wire:click="sendEmail" wire:loading.attr="disabled" wire:target="sendEmail"
                        icon="heroicon-o-envelope" color="warning"
                        wire:confirm="このメールを送信しますか？">
                        送信
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
