<?php

namespace Tests\Feature\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Models\AutoMessageSend;
use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Models\Listing;
use App\Models\Message;
use App\Services\AI\TranslationService;
use App\Services\AutoMessage\AutoMessageDispatcher;
use App\Services\AutoMessage\NameLanguageGuesser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AutoMessageDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 個別テストで override しない限り name-based guess は ja を返す設定にして
        // 既存の「翻訳なし」挙動を維持する。実 guesser は Latin 名 (John Doe) を
        // en 判定するので、指定しないと想定外の翻訳ケースを踏む。
        $guesser = Mockery::mock(NameLanguageGuesser::class);
        $guesser->shouldReceive('guess')->andReturn(['ja', 'Japanese'])->byDefault();
        $this->app->instance(NameLanguageGuesser::class, $guesser);
    }

    public function test_marks_due_pending_as_dry_run(): void
    {
        $send = $this->makePendingSend(['scheduled_at' => now()->subMinute()]);

        $result = app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertNotNull($send->rendered_body);
        $this->assertStringContainsString('John', $send->rendered_body);
        $this->assertSame(1, $result['dry_run']);
    }

    public function test_does_not_process_future_pending(): void
    {
        $send = $this->makePendingSend(['scheduled_at' => now()->addHour()]);

        $result = app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_PENDING, $send->status);
        $this->assertSame(0, $result['processed']);
    }

    public function test_skips_cancelled_booking(): void
    {
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['status' => Booking::STATUS_CANCELLED],
        );

        $result = app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_SKIPPED, $send->status);
        $this->assertSame(AutoMessageSend::SKIP_CANCELLED, $send->skip_reason);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_cancelled_trigger_delivers_to_cancelled_booking(): void
    {
        // 通常 cancelled は skip されるが、cancelled トリガーだけは送る
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['status' => Booking::STATUS_CANCELLED],
            ruleOverrides: [
                'trigger_event' => AutoMessageRule::EVENT_CANCELLED,
                'offset_minutes' => 15,
                'offset_days' => null,
                'time_of_day' => null,
            ],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
    }

    public function test_skips_when_booking_opted_out(): void
    {
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['auto_message_opt_out' => true],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_SKIPPED, $send->status);
        $this->assertSame(AutoMessageSend::SKIP_OPTED_OUT, $send->skip_reason);
    }

    public function test_skips_after_departure_only_when_flag_enabled(): void
    {
        // 1泊予約 (arrival=day1, departure=day2) に checkin+2days (day3 10:00)
        // only_when_staying=true なら skip する
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: [
                'arrival' => now()->subDays(3)->toDateString(),
                'departure' => now()->subDays(2)->toDateString(),
            ],
            ruleOverrides: [
                'trigger_event' => AutoMessageRule::EVENT_CHECKIN,
                'offset_days' => 2,
                'only_when_staying' => true,
            ],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_SKIPPED, $send->status);
        $this->assertSame(AutoMessageSend::SKIP_AFTER_DEPARTURE, $send->skip_reason);
    }

    public function test_delivers_after_departure_when_flag_disabled(): void
    {
        // only_when_staying=false（デフォルト）なら departure 過ぎでも送る
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: [
                'arrival' => now()->subDays(3)->toDateString(),
                'departure' => now()->subDays(2)->toDateString(),
            ],
            ruleOverrides: [
                'trigger_event' => AutoMessageRule::EVENT_CHECKIN,
                'offset_days' => 2,
                'only_when_staying' => false,
            ],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
    }

    public function test_skips_when_booking_time_before_rule_enabled_from(): void
    {
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['booking_time' => '2026-08-31 23:59:59'],
            ruleOverrides: ['is_enabled' => true, 'enabled_from' => '2026-09-01 00:00:00'],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_SKIPPED, $send->status);
        $this->assertSame(AutoMessageSend::SKIP_BEFORE_ENABLED_FROM, $send->skip_reason);
    }

    public function test_skips_when_rule_enabled_from_null(): void
    {
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['is_enabled' => true, 'enabled_from' => null],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_SKIPPED, $send->status);
        $this->assertSame(AutoMessageSend::SKIP_BEFORE_ENABLED_FROM, $send->skip_reason);
    }

    public function test_translates_when_rule_locale_is_non_ja(): void
    {
        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldReceive('translateFromJa')
            ->once()
            ->with(Mockery::pattern('/Hello John/'), 'en', null)
            ->andReturn('Hello John (translated)');
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['locale' => 'en'],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertStringContainsString('Hello John', $send->rendered_body);
        $this->assertSame('Hello John (translated)', $send->rendered_body_translated);
        $this->assertSame('en', $send->translated_to_lang);
    }

    public function test_auto_detects_target_language_from_latest_guest_message(): void
    {
        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldReceive('translateFromJa')
            ->once()
            ->with(Mockery::any(), 'en', 'English')
            ->andReturn('translated body');
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['locale' => null],
        );
        Message::create([
            'beds24_message_id' => 999001,
            'beds24_booking_id' => $send->booking->beds24_booking_id,
            'beds24_property_id' => $send->booking->beds24_property_id,
            'beds24_room_id' => $send->booking->beds24_room_id,
            'source' => Message::SOURCE_GUEST,
            'detected_language' => 'en',
            'detected_language_label' => 'English',
            'message' => 'Hello, do you have wifi?',
            'sent_at' => now()->subHour(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame('translated body', $send->rendered_body_translated);
        $this->assertSame('en', $send->translated_to_lang);
    }

    public function test_uses_name_based_guess_when_no_guest_messages(): void
    {
        $guesser = Mockery::mock(NameLanguageGuesser::class);
        $guesser->shouldReceive('guess')
            ->once()
            ->with('Doe', 'John')
            ->andReturn(['en', 'English']);
        $this->app->instance(NameLanguageGuesser::class, $guesser);

        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldReceive('translateFromJa')
            ->once()
            ->with(Mockery::any(), 'en', 'English')
            ->andReturn('Hello John (translated)');
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['locale' => null],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertSame('Hello John (translated)', $send->rendered_body_translated);
        $this->assertSame('en', $send->translated_to_lang);
    }

    public function test_ignores_image_only_message_when_resolving_language(): void
    {
        // 画像添付メッセージ (detected_language='en' 汚染データ) は無視して、
        // その前の日本語ゲストメッセージ (detected_language='ja') を採用する。
        $guesser = Mockery::mock(NameLanguageGuesser::class);
        $guesser->shouldNotReceive('guess'); // 有効な ja メッセージが取れるので guess は不要
        $this->app->instance(NameLanguageGuesser::class, $guesser);

        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldNotReceive('translateFromJa'); // ja なので翻訳スキップ
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['locale' => null],
        );
        // 古い ja メッセージ
        Message::create([
            'beds24_message_id' => 999100,
            'beds24_booking_id' => $send->booking->beds24_booking_id,
            'beds24_property_id' => $send->booking->beds24_property_id,
            'beds24_room_id' => $send->booking->beds24_room_id,
            'source' => Message::SOURCE_GUEST,
            'detected_language' => 'ja',
            'detected_language_label' => 'Japanese',
            'message' => 'こんにちは、チェックイン時間を教えてください',
            'sent_at' => now()->subHours(2),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);
        // 新しい画像のみメッセージ（誤って en 判定されている想定）
        Message::create([
            'beds24_message_id' => 999101,
            'beds24_booking_id' => $send->booking->beds24_booking_id,
            'beds24_property_id' => $send->booking->beds24_property_id,
            'beds24_room_id' => $send->booking->beds24_room_id,
            'source' => Message::SOURCE_GUEST,
            'detected_language' => 'en',
            'detected_language_label' => 'English',
            'message' => '<a href="https://a0.muscache.com/im/pictures-signed/foo.jpeg?X-Amz=xyz">https://a0.muscache.com/im/pictures-signed/foo.jpeg?X-Amz=xyz</a>',
            'sent_at' => now()->subHour(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertNull($send->rendered_body_translated);
        $this->assertNull($send->translated_to_lang);
    }

    public function test_does_not_translate_when_target_language_is_ja(): void
    {
        // 明示 ja でも、rule.locale=null で最新ゲストメッセージなしでも、翻訳は掛からない
        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldNotReceive('translateFromJa');
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['locale' => 'ja'],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertNull($send->rendered_body_translated);
        $this->assertNull($send->translated_to_lang);
    }

    public function test_marks_failed_when_translation_throws(): void
    {
        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldReceive('translateFromJa')
            ->once()
            ->andThrow(new RuntimeException('API down'));
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['locale' => 'en'],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_FAILED, $send->status);
        $this->assertStringContainsString('translate:', (string) $send->error);
        $this->assertStringContainsString('API down', (string) $send->error);
        $this->assertNotNull($send->rendered_body);
    }

    public function test_renders_guest_fallback_when_booking_name_is_empty(): void
    {
        // direct 予約で名前欄が空の場合、BodyRenderer が "ゲスト" に置換して送信する
        // (各言語への翻訳でも hospitality 文脈に自然に訳される)
        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldNotReceive('translateFromJa');
        $this->app->instance(TranslationService::class, $translator);

        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['first_name' => null, 'last_name' => null],
            ruleOverrides: [
                'locale' => 'ja',
                'body' => '{{ $guest_first_name }}さん、ご予約ありがとうございます',
            ],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertSame('ゲストさん、ご予約ありがとうございます', $send->rendered_body);
    }

    public function test_does_not_skip_when_body_has_no_guest_name_variables(): void
    {
        // 名前空でもテンプレが $guest_first_name 等を使ってなければ通常送信
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['first_name' => null, 'last_name' => null],
            ruleOverrides: [
                'locale' => 'ja',
                'body' => 'ご予約ありがとうございます',
            ],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
    }

    public function test_guest_full_name_check_allows_partial_name(): void
    {
        // last_name だけあれば guest_full_name は非空になるので送信する
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['first_name' => null, 'last_name' => 'Doe'],
            ruleOverrides: [
                'locale' => 'ja',
                'body' => '{{ $guest_full_name }}さん',
            ],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
    }

    public function test_records_disabled_reason_when_rule_enabled(): void
    {
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            ruleOverrides: ['is_enabled' => true],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertSame(AutoMessageDispatcher::SKIP_SENDING_DISABLED, $send->skip_reason);
    }

    public function test_skips_when_condition_registration_incomplete_but_fully_registered(): void
    {
        // condition 付きルール + 予約人数分の GuestRegistration が揃っている → SKIP_CONDITION_UNMET
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['num_adult' => 1],
            ruleOverrides: ['condition' => AutoMessageRule::CONDITION_REGISTRATION_INCOMPLETE],
        );

        GuestRegistration::create([
            'booking_id' => $send->booking_id,
            'guest_number' => 1,
            'name' => 'Test',
            'address' => 'Somewhere',
            'nationality' => 'JP',
            'is_representative' => true,
            'submitted_at' => now(),
        ]);

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_SKIPPED, $send->status);
        $this->assertSame(AutoMessageSend::SKIP_CONDITION_UNMET, $send->skip_reason);
    }

    public function test_delivers_when_condition_registration_incomplete_and_not_registered(): void
    {
        // condition 付きルール + 登録 0 件 → 発火 (dry_run)
        $send = $this->makePendingSend(
            sendOverrides: ['scheduled_at' => now()->subMinute()],
            bookingOverrides: ['num_adult' => 2],
            ruleOverrides: ['condition' => AutoMessageRule::CONDITION_REGISTRATION_INCOMPLETE],
        );

        app(AutoMessageDispatcher::class)->dispatchDue();

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
    }

    /**
     * @param  array<string, mixed>  $sendOverrides
     * @param  array<string, mixed>  $bookingOverrides
     * @param  array<string, mixed>  $ruleOverrides
     */
    private function makePendingSend(array $sendOverrides = [], array $bookingOverrides = [], array $ruleOverrides = []): AutoMessageSend
    {
        $listing = Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'test',
            'display_name' => '2階',
        ]);

        $booking = Booking::create(array_merge([
            'beds24_booking_id' => 1000 + random_int(1, 999999),
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'api_source' => 'Airbnb',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'arrival' => '2026-09-20',
            'departure' => '2026-09-23',
            'booking_time' => '2026-09-13 10:00:00',
            'raw_payload' => [],
            'fetched_at' => now(),
        ], $bookingOverrides));

        $rule = AutoMessageRule::create(array_merge([
            'name' => 'test-rule',
            'trigger_event' => AutoMessageRule::EVENT_CHECKIN,
            'offset_days' => -3,
            'time_of_day' => '10:00',
            'channel_scope' => AutoMessageRule::CHANNEL_AIRBNB,
            'body' => 'Hello {{ $guest_first_name }}',
            'is_enabled' => false,
            'enabled_from' => '2020-01-01 00:00:00',
        ], $ruleOverrides));
        $rule->listings()->attach($listing->id);

        return AutoMessageSend::create(array_merge([
            'auto_message_rule_id' => $rule->id,
            'booking_id' => $booking->id,
            'scheduled_at' => now(),
            'status' => AutoMessageSend::STATUS_PENDING,
        ], $sendOverrides));
    }
}
