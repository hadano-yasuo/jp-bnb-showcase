@php
    $translations = require resource_path('views/guest-registrations/translations.php');
    $t = $translations[$locale] ?? $translations['ja'];
    $T = fn (string $key, array $params = []): string => strtr(
        $t[$key] ?? ($translations['ja'][$key] ?? $key),
        collect($params)->mapWithKeys(fn ($v, $k) => [':'.$k => (string) $v])->all(),
    );

    // メッセージパイプラインと同じセットに追いて 5 言語を追加。
    // 並びは母語話者数と当該物件の来訪頻度で最頻から。
    $languages = [
        'ja' => '日本語',
        'en' => 'English',
        'zh' => '中文',
        'ko' => '한국어',
        'es' => 'Español',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'pt' => 'Português',
        'th' => 'ไทย',
        'vi' => 'Tiếng Việt',
    ];

    $numAdult = (int) ($booking->num_adult ?? 0);
    $numChild = (int) ($booking->num_child ?? 0);
    $expectedCount = $numAdult + $numChild;
    $submittedCount = $registrations->count();
    $arrivalDefault = optional($booking->arrival)->format('Y-m-d').'T15:00';
    $departureDefault = optional($booking->departure)->format('Y-m-d').'T10:00';

    // 編集モード: URL の ?edit=N に該当する既存レコード。
    $isEditing = $editingRegistration !== null;
    $currentGuestNumber = $isEditing ? $editingRegistration->guest_number : $nextGuestNumber;

    // フォーム事前投入値。編集モードなら既存値、新規なら空 or old()。
    $prefill = [
        'name' => old('name', $isEditing ? $editingRegistration->name : ''),
        'address' => old('address', $isEditing ? $editingRegistration->address : ''),
        'age' => old('age', $isEditing ? $editingRegistration->age : ''),
        'sex' => old('sex', $isEditing ? $editingRegistration->sex : ''),
        'occupation' => old('occupation', $isEditing ? $editingRegistration->occupation : ''),
        'phone' => old('phone', $isEditing ? $editingRegistration->phone : ''),
        'previous_stay' => old('previous_stay', $isEditing ? $editingRegistration->previous_stay : ''),
        'next_destination' => old('next_destination', $isEditing ? $editingRegistration->next_destination : ''),
        'arrival_at' => old('arrival_at', $isEditing && $editingRegistration->arrival_at
            ? $editingRegistration->arrival_at->format('Y-m-d\TH:i')
            : $arrivalDefault),
        'departure_at' => old('departure_at', $isEditing && $editingRegistration->departure_at
            ? $editingRegistration->departure_at->format('Y-m-d\TH:i')
            : $departureDefault),
    ];
    $prefillNationalityType = old('nationality_type', $isEditing
        ? ($editingRegistration->nationality === '日本' ? 'japan' : 'other')
        : '');
    $prefillNationalityText = old('nationality', $isEditing && $editingRegistration->nationality !== '日本'
        ? $editingRegistration->nationality
        : '');
    $prefillIsRepresentative = old('is_representative', $isEditing
        ? ($editingRegistration->is_representative ? '1' : '')
        : '');

    // ゲスト向け verdict バッジ（AI 判定 or 管理者判定の effective 値）。
    // mismatches は管理者用なのでゲストには見せない。issues のみ表示。
    $guestVerdictMeta = function (?string $verdict): array {
        return match ($verdict) {
            'ok' => ['icon' => '✓', 'bg' => 'bg-green-100', 'text' => 'text-green-800'],
            'suspicious' => ['icon' => '⚠', 'bg' => 'bg-amber-100', 'text' => 'text-amber-800'],
            'invalid' => ['icon' => '✕', 'bg' => 'bg-red-100', 'text' => 'text-red-800'],
            'pending' => ['icon' => '…', 'bg' => 'bg-gray-100', 'text' => 'text-gray-600'],
            'error' => ['icon' => '?', 'bg' => 'bg-purple-100', 'text' => 'text-purple-700'],
            default => ['icon' => '', 'bg' => 'bg-gray-100', 'text' => 'text-gray-600'],
        };
    };
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $T('title') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon-32.png') }}" sizes="32x32" type="image/png">
    <link rel="icon" href="{{ asset('favicon-16.png') }}" sizes="16x16" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <meta name="theme-color" content="#173049">
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">
    <div class="max-w-2xl mx-auto px-4 py-8">
        {{-- 言語スイッチャー --}}
        <div class="mb-4 flex flex-wrap gap-1 justify-end text-xs">
            @foreach ($languages as $code => $label)
                <a href="{{ route('guest-registrations.show', ['token' => $token->token, 'lang' => $code]) }}"
                   class="rounded px-2 py-1 {{ $locale === $code ? 'bg-gray-900 text-white' : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-100' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <header class="mb-6">
            <h1 class="text-xl font-semibold">{{ $T('title') }}</h1>
            <p class="mt-1 text-sm text-gray-600">
                {{ $booking->listing?->name ?? '—' }}<br>
                {{ $T('checkin_short') }}: {{ optional($booking->arrival)->format('Y-m-d') }} /
                {{ $T('checkout_short') }}: {{ optional($booking->departure)->format('Y-m-d') }}
            </p>
        </header>

        @if (session('status') === 'submitted')
            <div class="mb-6 rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ $T('submitted_message') }}
            </div>
        @elseif (session('status') === 'updated')
            <div class="mb-6 rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ $T('updated_message') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded border-2 border-red-400 bg-red-50 px-4 py-3 text-sm text-red-900">
                <p class="font-semibold">⚠ {{ $T('errors_header') }}</p>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="mb-6 rounded-lg border-2 {{ $submittedCount >= $expectedCount && $expectedCount > 0 ? 'border-green-300 bg-green-50' : 'border-amber-300 bg-amber-50' }} p-4">
            <div class="flex justify-between items-center">
                <p class="font-semibold text-sm">
                    {{ $T('registration_status') }}
                </p>
                <span class="text-lg font-bold {{ $submittedCount >= $expectedCount && $expectedCount > 0 ? 'text-green-700' : 'text-amber-700' }}">
                    {{ $submittedCount }} / {{ $expectedCount > 0 ? $expectedCount : '?' }}
                </span>
            </div>
            @if ($expectedCount > 0)
                <p class="mt-1 text-xs text-gray-600">
                    {{ $T('booked_count_label') }}:
                    @if ($numChild > 0)
                        {{ $T('adult_count') }} {{ $numAdult }} + {{ $T('child_count') }} {{ $numChild }} = {{ $expectedCount }}
                    @else
                        {{ $T('adult_count') }} {{ $numAdult }}
                    @endif
                </p>
            @endif
            @if ($registrations->isNotEmpty())
                <ol class="mt-3 space-y-2 text-sm text-gray-800">
                    @foreach ($registrations as $r)
                        @php
                            $effective = $r->effectiveVerdict();
                            $meta = $guestVerdictMeta($effective);
                            $extracted = $r->id_image_ai_extracted ?? [];
                            $issues = is_array($extracted['issues'] ?? null) ? $extracted['issues'] : [];
                            $isBeingEdited = $isEditing && $editingRegistration->id === $r->id;
                        @endphp
                        <li class="rounded border {{ $isBeingEdited ? 'border-blue-400 bg-blue-50' : 'border-transparent' }} p-2">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="inline-block w-6 text-center text-xs rounded-full {{ $meta['bg'] }} {{ $meta['text'] }} font-bold">{{ $meta['icon'] }}</span>
                                <span class="font-medium">{{ $r->name }}</span>
                                <span class="text-gray-500 text-xs">({{ $r->nationality }})</span>
                                @if ($r->is_representative)
                                    <span class="text-[10px] rounded bg-blue-100 text-blue-700 px-1.5 py-0.5">{{ $T('representative_tag') }}</span>
                                @endif
                                @if ($effective && in_array($effective, ['ok', 'suspicious', 'invalid'], true))
                                    <span class="text-[10px] rounded {{ $meta['bg'] }} {{ $meta['text'] }} px-1.5 py-0.5">{{ $T('verdict_'.$effective) }}</span>
                                @endif
                                <span class="ml-auto">
                                    @if ($isBeingEdited)
                                        <span class="text-xs text-blue-700">{{ $T('editing_now') }}</span>
                                    @else
                                        <a href="{{ route('guest-registrations.show', ['token' => $token->token, 'lang' => $locale, 'edit' => $r->guest_number]) }}"
                                           class="text-xs rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-100 px-2 py-0.5">
                                            {{ $T('edit_button') }}
                                        </a>
                                    @endif
                                </span>
                            </div>
                            @if (in_array($effective, ['suspicious', 'invalid'], true) && $issues !== [])
                                <details class="mt-1 text-xs {{ $meta['text'] }}">
                                    <summary class="cursor-pointer">{{ $T('why_flagged') }}</summary>
                                    <ul class="mt-1 list-disc pl-4">
                                        @foreach ($issues as $issue)
                                            <li>{{ $issue }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ol>
                <p class="mt-3 text-xs text-gray-600">{{ $T('already_registered_note') }}</p>
            @else
                <p class="mt-2 text-xs text-gray-600">{{ $T('no_registrations_yet') }}</p>
            @endif
        </section>

        <section class="mb-6 rounded border border-gray-200 bg-white p-4 text-sm">
            <p class="font-semibold">{{ $T('notice_title') }}</p>
            <ul class="mt-2 list-disc pl-5 space-y-1 text-gray-700">
                <li>{{ $T('notice_all_register') }}</li>
                <li>{{ $T('notice_id_rule') }}</li>
                <li>{{ $T('notice_one_per_submit') }}</li>
                <li class="text-amber-800">{{ $T('notice_extra_fee') }}</li>
                <li>{{ $T('notice_contact_if_wrong') }}</li>
            </ul>
        </section>

        <form method="POST"
              action="{{ route('guest-registrations.store', array_filter(['token' => $token->token, 'lang' => $locale, 'edit' => $isEditing ? $editingRegistration->guest_number : null])) }}"
              enctype="multipart/form-data"
              class="space-y-5 rounded border {{ $isEditing ? 'border-blue-400' : 'border-gray-200' }} bg-white p-6"
              x-data="guestForm({
                  options: {{ Js::from($copyOptions) }},
                  hasRepresentative: {{ $hasRepresentative ? 'true' : 'false' }},
                  isFirst: {{ $currentGuestNumber === 1 ? 'true' : 'false' }},
                  isEditing: {{ $isEditing ? 'true' : 'false' }},
                  initialRepresentative: @js($prefillIsRepresentative === '1'),
                  initialAge: {{ (int) ($prefill['age'] ?: 0) }},
                  initialNationalityType: @js($prefillNationalityType),
                  labels: {
                      chooseImage: @js($T('id_image_choose')),
                      changeImage: @js($T('id_image_change')),
                  },
              })">
            @csrf

            <div>
                <p class="text-sm font-semibold">
                    @if ($isEditing)
                        {{ $T('editing_guest_n', ['n' => $currentGuestNumber]) }}
                    @else
                        {{ $T('guest_n_info', ['n' => $currentGuestNumber]) }}
                    @endif
                </p>
                @if ($isEditing)
                    <p class="mt-1 text-xs text-blue-700">
                        {{ $T('editing_help') }}
                        <a href="{{ route('guest-registrations.show', ['token' => $token->token, 'lang' => $locale]) }}" class="underline">{{ $T('cancel_edit') }}</a>
                    </p>
                @endif
            </div>

            @if (! $isEditing && ! empty($copyOptions))
                <div class="rounded border border-blue-200 bg-blue-50 p-3">
                    <label class="block text-xs font-semibold text-blue-900">
                        {{ $T('copy_from_other') }}
                    </label>
                    <select x-model="copyFrom"
                            @change="applyCopy()"
                            class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                        <option value="">{{ $T('select_placeholder') }}</option>
                        @foreach ($copyOptions as $opt)
                            <option value="{{ $opt['guest_number'] }}">{{ $opt['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-blue-800">{{ $T('copy_hint') }}</p>
                </div>
            @endif

            {{-- 代表者チェックボックス --}}
            @php $repLocked = $hasRepresentative && ! $prefillIsRepresentative; @endphp
            <div class="rounded border {{ $repLocked ? 'border-gray-200 bg-gray-50' : 'border-blue-200 bg-blue-50' }} p-3">
                <label class="flex items-start gap-2 {{ $repLocked ? 'cursor-not-allowed' : 'cursor-pointer' }}">
                    <input type="checkbox" name="is_representative" value="1" x-model="isRepresentative"
                           @if ($repLocked) disabled @endif
                           class="mt-0.5">
                    <div class="text-sm">
                        <div class="font-semibold {{ $repLocked ? 'text-gray-500' : '' }}">{{ $T('register_as_representative') }}</div>
                        <div class="text-xs text-gray-600 mt-0.5">
                            {{ $T('representative_note') }}
                            @if ($repLocked)
                                <span class="block mt-1 text-amber-700">{{ $T('representative_already_exists') }}</span>
                            @endif
                        </div>
                    </div>
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium">{{ $T('name') }} <span class="text-red-500">*</span></label>
                <input type="text" name="name" required maxlength="255" x-ref="name"
                       value="{{ $prefill['name'] }}"
                       class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium">{{ $T('address') }} <span class="text-red-500">*</span></label>
                <input type="text" name="address" required maxlength="500" x-ref="address"
                       value="{{ $prefill['address'] }}"
                       class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                <p class="mt-1 text-xs text-gray-500">{{ $T('address_hint') }}</p>
                @error('address') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium">{{ $T('age') }} <span class="text-red-500">*</span></label>
                    <input type="number" name="age" required min="0" max="120"
                           x-model.number="age"
                           value="{{ $prefill['age'] }}"
                           class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                    @error('age') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium">{{ $T('sex') }} <span class="text-red-500">*</span></label>
                    <select name="sex" required class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                        <option value="">{{ $T('sex_select') }}</option>
                        <option value="male" @selected($prefill['sex'] === 'male')>{{ $T('sex_male') }}</option>
                        <option value="female" @selected($prefill['sex'] === 'female')>{{ $T('sex_female') }}</option>
                        <option value="other" @selected($prefill['sex'] === 'other')>{{ $T('sex_other') }}</option>
                        <option value="prefer_not_to_say" @selected($prefill['sex'] === 'prefer_not_to_say')>{{ $T('sex_prefer_not_to_say') }}</option>
                    </select>
                    @error('sex') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">{{ $T('occupation') }}</label>
                <input type="text" name="occupation" maxlength="255" x-ref="occupation"
                       value="{{ $prefill['occupation'] }}"
                       class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                @error('occupation') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium">{{ $T('nationality') }} <span class="text-red-500">*</span></label>
                <div class="mt-1 space-y-2">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="nationality_type" value="japan"
                               x-model="nationalityType" required>
                        <span>{{ $T('nationality_japan') }}</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="nationality_type" value="other"
                               x-model="nationalityType" required>
                        <span>{{ $T('nationality_other') }}</span>
                    </label>
                    <input type="text" name="nationality" maxlength="100" x-ref="nationality"
                           x-show="nationalityType === 'other'"
                           :required="nationalityType === 'other'"
                           value="{{ $prefillNationalityText }}"
                           placeholder="{{ $T('nationality_country_placeholder') }}"
                           class="w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                </div>
                @error('nationality_type') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('nationality') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium">{{ $T('previous_stay') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="previous_stay" required maxlength="255" x-ref="previous_stay"
                           value="{{ $prefill['previous_stay'] }}"
                           class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                    @error('previous_stay') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium">{{ $T('next_destination') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="next_destination" required maxlength="255" x-ref="next_destination"
                           value="{{ $prefill['next_destination'] }}"
                           class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                    @error('next_destination') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- 連絡先（全員必須） --}}
            <div>
                <label class="block text-sm font-medium">{{ $T('phone') }} <span class="text-red-500">*</span></label>
                @if ($representativePhone && ! $prefillIsRepresentative)
                    <label class="mt-1 flex items-center gap-2 text-xs text-gray-700">
                        <input type="checkbox" x-model="sameAsRepPhone">
                        <span>{{ $T('same_as_rep_contact') }}</span>
                    </label>
                @endif
                <input type="text" name="phone" maxlength="64" required x-ref="phone"
                       x-effect="if (sameAsRepPhone) { $refs.phone.value = @js($representativePhone ?? ''); }"
                       :readonly="sameAsRepPhone"
                       :class="sameAsRepPhone ? 'bg-gray-100 text-gray-500' : ''"
                       value="{{ $prefill['phone'] }}"
                       placeholder="{{ $T('phone_placeholder') }}"
                       class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                @error('phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- 到着・出発日時（代表者のみ入力） --}}
            <div x-show="isRepresentative" x-transition class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium">
                        {{ $T('arrival_at') }} <span class="text-red-500" x-show="isRepresentative">*</span>
                    </label>
                    <input type="datetime-local" name="arrival_at"
                           :required="isRepresentative"
                           value="{{ $prefill['arrival_at'] }}"
                           class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                    @error('arrival_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium">
                        {{ $T('departure_at') }} <span class="text-red-500" x-show="isRepresentative">*</span>
                    </label>
                    <input type="datetime-local" name="departure_at"
                           :required="isRepresentative"
                           value="{{ $prefill['departure_at'] }}"
                           class="mt-1 w-full rounded border border-gray-400 bg-white px-2 py-1.5 text-sm shadow-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none">
                    @error('departure_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">
                    {{ $T('id_image') }}
                    <span class="text-red-500" x-show="idImageRequired && ! hasExistingImage">*</span>
                </label>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ $T('id_image_examples') }}<br>
                    <span class="block text-[11px] text-gray-400 mt-0.5">{{ $T('id_image_formats') }}</span>
                    @if ($isEditing && $editingRegistration->id_image_path)
                        <span class="text-green-700">{{ $T('id_image_current_exists') }}</span>
                    @else
                        <span x-show="idImageRequired" class="font-semibold text-red-600">{{ $T('id_required') }}</span>
                        <span x-show="! idImageRequired">{{ $T('id_optional_reason') }}</span>
                    @endif
                </p>
                <input type="file" name="id_image" accept="image/*"
                       class="mt-2 block w-full text-sm text-gray-700
                              file:mr-4 file:rounded-md file:border-2 file:border-blue-400
                              file:bg-blue-50 file:px-5 file:py-2.5
                              file:text-sm file:font-semibold file:text-blue-800
                              file:cursor-pointer hover:file:bg-blue-100 hover:file:border-blue-500">
                @error('id_image') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            {{-- 個人情報の取扱いに関する同意 --}}
            <div class="rounded border border-gray-300 bg-gray-50 p-3 text-xs text-gray-700"
                 x-data="{ open: false }">
                <button type="button"
                        @click="open = ! open"
                        class="w-full flex justify-between items-start gap-2 text-left"
                        :aria-expanded="open">
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-sm text-gray-900">{{ $T('privacy_title') }}</div>
                        <div x-show="! open"
                             class="mt-0.5 text-gray-600 line-clamp-1 text-[11px]">
                            {{ $T('privacy_purpose') }}
                        </div>
                    </div>
                    <span class="text-gray-500 text-xs pt-0.5"
                          x-text="open ? '▲' : '▼'"></span>
                </button>
                <div x-show="open" x-transition class="mt-2 space-y-2">
                    <p>{{ $T('privacy_operator') }}</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li>{{ $T('privacy_purpose') }}</li>
                        <li>{{ $T('privacy_verification') }}</li>
                        <li>{{ $T('privacy_security') }}</li>
                        <li>{{ $T('privacy_retention') }}</li>
                        <li>{{ $T('privacy_third_party') }}</li>
                        <li>{{ $T('privacy_refusal') }}</li>
                    </ul>
                </div>
                <label class="flex items-start gap-2 mt-3 cursor-pointer pt-3 border-t border-gray-300">
                    <input type="checkbox" name="privacy_consent" value="1" required
                           @checked(old('privacy_consent') === '1')
                           class="mt-0.5">
                    <span class="text-sm text-gray-900">{{ $T('privacy_consent_label') }} <span class="text-red-500">*</span></span>
                </label>
                @error('privacy_consent') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="pt-2">
                <button type="submit"
                        class="w-full rounded {{ $isEditing ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-900 hover:bg-gray-800' }} px-4 py-2 text-sm font-semibold text-white">
                    {{ $isEditing ? $T('update_button') : $T('submit') }}
                </button>
            </div>
        </form>

        <p class="mt-6 text-center text-xs text-gray-500">
            {{ $T('valid_until') }}: {{ $token->expires_at->format('Y-m-d') }}
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.9/dist/cdn.min.js" defer></script>
    <script>
        function guestForm({ options, hasRepresentative, isFirst, isEditing, initialRepresentative, initialAge, initialNationalityType, labels }) {
            return {
                copyFrom: '',
                options,
                // 編集モード時は既存の値を優先。新規時はまだ代表者が居ないなら初期チェック。
                isRepresentative: isEditing ? initialRepresentative : (! hasRepresentative && isFirst),
                age: initialAge,
                nationalityType: initialNationalityType,
                // 「代表者と同じ連絡先」チェック。代表者以外のときのみ表示される。
                sameAsRepPhone: false,
                hasExistingImage: {{ $isEditing && $editingRegistration->id_image_path ? 'true' : 'false' }},
                get idImageRequired() {
                    const japaneseMinor = this.nationalityType === 'japan' && Number(this.age) < 18;
                    return ! japaneseMinor;
                },
                applyCopy() {
                    if (! this.copyFrom) return;
                    const src = this.options.find(o => String(o.guest_number) === String(this.copyFrom));
                    if (! src) return;
                    // 氏名・年齢・性別・代表者フラグは意図的にコピーしない
                    ['address', 'occupation', 'previous_stay', 'next_destination'].forEach(k => {
                        if (this.$refs[k] && src[k]) this.$refs[k].value = src[k];
                    });
                    // 国籍は radio + text の 2 段構え
                    if (src.nationality === '日本') {
                        this.nationalityType = 'japan';
                    } else if (src.nationality) {
                        this.nationalityType = 'other';
                        this.$nextTick(() => {
                            if (this.$refs.nationality) this.$refs.nationality.value = src.nationality;
                        });
                    }
                },
            };
        }
    </script>
</body>
</html>
