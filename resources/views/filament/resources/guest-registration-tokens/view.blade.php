<x-filament-panels::page>
    <div class="space-y-6">
        @if ($token->isManuallyCompleted())
            <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-950 p-4">
                <div class="flex items-start gap-3">
                    <div class="text-green-700 dark:text-green-400 text-xl leading-none">✓</div>
                    <div class="flex-1 text-sm">
                        <div class="font-semibold text-green-800 dark:text-green-200">
                            手動で収集完了済み
                        </div>
                        <div class="mt-1 text-green-700 dark:text-green-300">
                            {{ $token->manuallyCompletedBy?->name ?? '—' }} が
                            {{ $token->manually_completed_at?->format('Y-m-d H:i') ?? '—' }} に設定
                        </div>
                        @if (! empty($token->manually_completed_note))
                            <div class="mt-2 whitespace-pre-wrap text-green-800 dark:text-green-200">
                                {{ $token->manually_completed_note }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                    <span class="text-gray-500">物件</span>
                    <div class="font-medium">{{ $booking->listing?->name ?? '—' }}</div>
                </div>
                <div>
                    <span class="text-gray-500">予約者</span>
                    <div class="font-medium">{{ trim(($booking->first_name ?? '').' '.($booking->last_name ?? '')) ?: '—' }}</div>
                </div>
                <div>
                    <span class="text-gray-500">チェックイン</span>
                    <div class="font-medium">{{ optional($booking->arrival)->format('Y-m-d') }}</div>
                </div>
                <div>
                    <span class="text-gray-500">チェックアウト</span>
                    <div class="font-medium">{{ optional($booking->departure)->format('Y-m-d') }}</div>
                </div>
                <div>
                    <span class="text-gray-500">大人 / 子供</span>
                    <div class="font-medium">{{ $booking->num_adult ?? 0 }} / {{ $booking->num_child ?? 0 }}</div>
                </div>
                <div>
                    <span class="text-gray-500">予約チャネル</span>
                    <div class="font-medium">
                        @php
                            $sourceLabel = match ($booking->api_source) {
                                'Airbnb' => '🏠 Airbnb',
                                'Booking.com' => '🅱️ Booking.com',
                                'Direct' => '🏢 Direct',
                                null => '—',
                                default => $booking->api_source,
                            };
                        @endphp
                        {{ $sourceLabel }}
                    </div>
                </div>
                <div>
                    <span class="text-gray-500">beds24 予約ID</span>
                    <div class="font-medium text-xs font-mono">{{ $booking->beds24_booking_id }}</div>
                </div>
                <div>
                    <span class="text-gray-500">URL 有効期限</span>
                    <div class="font-medium">{{ $token->expires_at->format('Y-m-d H:i') }}</div>
                </div>
            </div>

            <div class="mt-4 rounded bg-gray-50 dark:bg-gray-800 p-3">
                <div class="text-xs text-gray-500">公開URL（ゲストに送る）</div>
                <div class="mt-1 flex items-center gap-2">
                    <input type="text"
                           readonly
                           value="{{ $publicUrl }}"
                           class="flex-1 text-sm bg-transparent border-none px-0"
                           onclick="this.select()">
                </div>
            </div>
        </div>

        <div>
            <h2 class="text-lg font-semibold mb-3">
                登録済みゲスト情報 ({{ $registrations->count() }} 名)
            </h2>

            @if ($registrations->isEmpty())
                <p class="text-sm text-gray-500">まだ登録されていません。</p>
            @else
                <div class="space-y-4">
                    @foreach ($registrations as $registration)
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <div class="text-sm text-gray-500 flex items-center gap-2">
                                        <span>#{{ $registration->guest_number }}</span>
                                        @if ($registration->is_representative)
                                            <span class="text-[10px] rounded bg-blue-100 text-blue-700 px-1.5 py-0.5">👑 代表者</span>
                                        @endif
                                    </div>
                                    <div class="font-semibold">{{ $registration->name }}</div>
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $registration->submitted_at->format('Y-m-d H:i') }}
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
                                <div><span class="text-gray-500">住所:</span> {{ $registration->address }}</div>
                                <div><span class="text-gray-500">国籍:</span> {{ $registration->nationality }}</div>
                                <div>
                                    <span class="text-gray-500">性別:</span>
                                    @php
                                        $sexLabel = match ($registration->sex) {
                                            'male' => '男',
                                            'female' => '女',
                                            'other' => 'その他',
                                            'prefer_not_to_say' => '回答しない',
                                            default => '—',
                                        };
                                    @endphp
                                    {{ $sexLabel }}
                                </div>
                                <div><span class="text-gray-500">年齢:</span> {{ $registration->age ?? '—' }}</div>
                                <div><span class="text-gray-500">職業:</span> {{ $registration->occupation ?? '—' }}</div>
                                <div><span class="text-gray-500">連絡先:</span> {{ $registration->phone ?? '—' }}</div>
                                <div><span class="text-gray-500">前泊地:</span> {{ $registration->previous_stay ?? '—' }}</div>
                                <div><span class="text-gray-500">行先地:</span> {{ $registration->next_destination ?? '—' }}</div>
                                @if ($registration->is_representative)
                                    <div><span class="text-gray-500">到着日時:</span> {{ optional($registration->arrival_at)->format('Y-m-d H:i') ?? '—' }}</div>
                                    <div><span class="text-gray-500">出発日時:</span> {{ optional($registration->departure_at)->format('Y-m-d H:i') ?? '—' }}</div>
                                @endif
                                <div>
                                    <span class="text-gray-500">画質:</span>
                                    {{ $registration->id_image_compressed ? '軽量化済み' : '原本' }}
                                </div>
                            </div>
                            @php $dupes = $duplicates[$registration->id] ?? []; @endphp
                            @if (! empty($dupes))
                                <div class="mt-3 rounded border-2 border-red-400 bg-red-50 p-3 text-xs">
                                    <div class="font-semibold text-red-900">🚨 重複画像を検出しました</div>
                                    <ul class="mt-2 space-y-1 text-red-900">
                                        @foreach ($dupes as $dupe)
                                            @php
                                                $d = $dupe['registration'];
                                                $badge = $dupe['type'] === 'exact' ? '完全一致 (SHA-256)' : 'AI 抽出氏名一致';
                                            @endphp
                                            <li>
                                                <span class="font-semibold">{{ $badge }}:</span>
                                                <a href="{{ route('filament.admin.resources.guest-registration-tokens.view', ['record' => optional($d->booking?->guestRegistrationToken)->id ?? '#']) }}"
                                                   class="underline">
                                                    Booking #{{ $d->booking_id }} / Guest #{{ $d->guest_number }} {{ $d->name }}
                                                </a>
                                                （{{ optional($d->submitted_at)->format('Y-m-d H:i') }} 提出）
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($registration->id_image_path)
                                @php
                                    $effective = $registration->effectiveVerdict();
                                    $verdictMeta = match ($effective) {
                                        'ok' => ['label' => 'OK / 検証済み', 'bg' => 'bg-green-50', 'border' => 'border-green-300', 'text' => 'text-green-800'],
                                        'suspicious' => ['label' => '疑わしい', 'bg' => 'bg-amber-50', 'border' => 'border-amber-400', 'text' => 'text-amber-900'],
                                        'invalid' => ['label' => '要確認 / 不正な可能性', 'bg' => 'bg-red-50', 'border' => 'border-red-400', 'text' => 'text-red-900'],
                                        'pending' => ['label' => '確認中...', 'bg' => 'bg-gray-50', 'border' => 'border-gray-300', 'text' => 'text-gray-700'],
                                        'error' => ['label' => '検証エラー', 'bg' => 'bg-purple-50', 'border' => 'border-purple-400', 'text' => 'text-purple-900'],
                                        default => null,
                                    };
                                    $hasAdminOverride = $registration->admin_verdict !== null;
                                @endphp

                                @if ($verdictMeta)
                                    <div class="mt-3 rounded border-2 {{ $verdictMeta['border'] }} {{ $verdictMeta['bg'] }} p-3">
                                        <div class="flex justify-between items-center flex-wrap gap-2">
                                            <span class="text-sm font-semibold {{ $verdictMeta['text'] }}">
                                                @if ($hasAdminOverride)
                                                    👤 管理者判定: {{ $verdictMeta['label'] }}
                                                @else
                                                    🤖 AI 画像確認: {{ $verdictMeta['label'] }}
                                                @endif
                                            </span>
                                            @if ($registration->id_image_verified_at)
                                                <span class="text-xs text-gray-500">
                                                    AI: {{ $registration->id_image_verified_at->format('m-d H:i') }}
                                                </span>
                                            @endif
                                        </div>
                                        @if ($hasAdminOverride)
                                            <div class="mt-2 text-xs {{ $verdictMeta['text'] }}">
                                                by {{ $registration->adminReviewer?->name ?? '—' }}
                                                ({{ optional($registration->admin_reviewed_at)->format('Y-m-d H:i') }})
                                                @if ($registration->admin_verdict_reason)
                                                    <div class="mt-1 whitespace-pre-line">理由: {{ $registration->admin_verdict_reason }}</div>
                                                @endif
                                                @if ($registration->id_image_verdict)
                                                    <div class="mt-1 text-gray-500">
                                                        （AI 判定: {{ $registration->id_image_verdict }}）
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                        @if ($registration->id_image_ai_notes)
                                            <div class="mt-2 whitespace-pre-line text-xs {{ $verdictMeta['text'] }}">{{ $registration->id_image_ai_notes }}</div>
                                        @endif
                                        @if ($registration->id_image_ai_extracted)
                                            @php $ext = $registration->id_image_ai_extracted; @endphp
                                            <details class="mt-2 text-xs">
                                                <summary class="cursor-pointer text-gray-600">AI 抽出情報</summary>
                                                <dl class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-gray-700">
                                                    @if (! empty($ext['document_type']))
                                                        <dt class="text-gray-500">書類種別</dt><dd>{{ $ext['document_type'] }}</dd>
                                                    @endif
                                                    @if (! empty($ext['extracted_name']))
                                                        <dt class="text-gray-500">氏名 (AI)</dt>
                                                        <dd>
                                                            {{ $ext['extracted_name'] }}
                                                            @if (mb_strtolower(preg_replace('/\s+/', '', (string) $ext['extracted_name'])) !== mb_strtolower(preg_replace('/\s+/', '', $registration->name)))
                                                                <span class="ml-1 text-red-600 font-semibold">⚠ 不一致</span>
                                                            @endif
                                                        </dd>
                                                    @endif
                                                    @if (! empty($ext['extracted_birth_date']))
                                                        <dt class="text-gray-500">生年月日</dt><dd>{{ $ext['extracted_birth_date'] }}</dd>
                                                    @endif
                                                    @if (! empty($ext['extracted_nationality']))
                                                        <dt class="text-gray-500">国籍 (AI)</dt><dd>{{ $ext['extracted_nationality'] }}</dd>
                                                    @endif
                                                    @if (! empty($ext['expiry_date']))
                                                        <dt class="text-gray-500">有効期限</dt><dd>{{ $ext['expiry_date'] }}</dd>
                                                    @endif
                                                    @if (isset($ext['confidence']))
                                                        <dt class="text-gray-500">Confidence</dt><dd>{{ number_format((float) $ext['confidence'] * 100, 0) }}%</dd>
                                                    @endif
                                                    @if (! empty($ext['_model']))
                                                        <dt class="text-gray-500">Model</dt><dd class="text-[10px]">{{ $ext['_model'] }}</dd>
                                                    @endif
                                                </dl>
                                            </details>
                                        @endif

                                        {{-- 管理者アクション --}}
                                        <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700"
                                             x-data="{ open: false, reason: @js($registration->admin_verdict_reason ?? '') }">
                                            <div class="flex gap-2 flex-wrap">
                                                @if ($hasAdminOverride)
                                                    <button type="button"
                                                            wire:click="clearAdminVerdict({{ $registration->id }})"
                                                            class="text-xs rounded bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1">
                                                        🔄 AI 判定に戻す
                                                    </button>
                                                @endif
                                                <button type="button"
                                                        @click="open = ! open"
                                                        class="text-xs rounded bg-gray-100 hover:bg-gray-200 text-gray-700 px-3 py-1">
                                                    <span x-show="! open">📝 判定を上書き</span>
                                                    <span x-show="open">▲ 折りたたむ</span>
                                                </button>
                                            </div>
                                            <div x-show="open" x-transition class="mt-2 space-y-2">
                                                <textarea x-model="reason"
                                                          rows="2"
                                                          placeholder="判定の理由（任意）..."
                                                          class="w-full text-xs rounded border border-gray-300 px-2 py-1 bg-white dark:bg-gray-800"></textarea>
                                                <div class="flex gap-2">
                                                    <button type="button"
                                                            @click="$wire.approveRegistration({{ $registration->id }}, reason); open = false"
                                                            class="text-xs rounded bg-green-600 hover:bg-green-700 text-white px-3 py-1 font-semibold">
                                                        ✅ 承認する
                                                    </button>
                                                    <button type="button"
                                                            @click="$wire.rejectRegistration({{ $registration->id }}, reason); open = false"
                                                            class="text-xs rounded bg-red-600 hover:bg-red-700 text-white px-3 py-1 font-semibold">
                                                        ❌ 却下する
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if ($imageUrls[$registration->id] ?? null)
                                    <div class="mt-3">
                                        <a href="{{ $imageUrls[$registration->id] }}" target="_blank" rel="noopener">
                                            <img src="{{ $imageUrls[$registration->id] }}"
                                                 alt="ID image #{{ $registration->guest_number }}"
                                                 class="max-h-64 rounded border border-gray-200 dark:border-gray-700">
                                        </a>
                                    </div>
                                @else
                                    <p class="mt-3 text-xs text-red-600">画像の署名URL生成に失敗しました。</p>
                                @endif
                            @else
                                <p class="mt-3 text-xs text-gray-500">画像未提出</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
