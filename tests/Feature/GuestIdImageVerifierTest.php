<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Services\GuestIdImage\GuestIdImageVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestIdImageVerifierTest extends TestCase
{
    use RefreshDatabase;

    private const ANTHROPIC_URL = 'https://api.anthropic.example/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        config([
            'guest_id_image.disk' => 'r2',
            'anthropic.api_key' => 'sk-ant-test',
            'anthropic.base_url' => 'https://api.anthropic.example/v1',
            'anthropic.model' => 'claude-sonnet-4-6',
            'anthropic.api_version' => '2023-06-01',
            'anthropic.http_timeout' => 5,
        ]);
    }

    public function test_verify_saves_verdict_from_ai_response(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply(json_encode([
                'verdict' => 'ok',
                'confidence' => 0.95,
                'document_type' => 'passport',
                'extracted_name' => 'TANAKA TARO',
                'extracted_birth_date' => '1990-01-15',
                'extracted_nationality' => 'JPN',
                'expiry_date' => '2030-01-01',
                'mismatches' => [],
                'issues' => [],
            ]))),
        ]);

        $registration = $this->makeRegistration();
        Storage::disk('r2')->put($registration->id_image_path, 'fake-image-data');

        app(GuestIdImageVerifier::class)->verify($registration);

        $fresh = $registration->fresh();
        $this->assertSame('ok', $fresh->id_image_verdict);
        $this->assertSame('TANAKA TARO', $fresh->id_image_ai_extracted['extracted_name']);
        $this->assertNotNull($fresh->id_image_verified_at);
    }

    public function test_verify_passes_submitted_info_to_prompt_for_crosscheck(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply(json_encode([
                'verdict' => 'ok',
                'confidence' => 0.9,
                'issues' => [],
                'mismatches' => [],
            ]))),
        ]);

        $registration = $this->makeRegistration([
            'name' => 'テスト太郎',
            'age' => 42,
            'nationality' => '日本',
        ]);
        Storage::disk('r2')->put($registration->id_image_path, 'fake-image-data');

        app(GuestIdImageVerifier::class)->verify($registration);

        // クロスチェック用に user content（text ブロック）に提出値が渡されている
        Http::assertSent(function ($req) {
            $body = $req->data();
            $userContent = $body['messages'][0]['content'];
            // multimodal 配列。1 つめは image、2 つめは text
            $textBlock = collect($userContent)->firstWhere('type', 'text');
            $text = $textBlock['text'] ?? '';

            return str_contains($text, 'テスト太郎')
                && str_contains($text, '42')
                && str_contains($text, '日本');
        });
    }

    public function test_verify_upgrades_verdict_when_expiry_is_before_checkout(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply(json_encode([
                'verdict' => 'ok',
                'confidence' => 0.9,
                'expiry_date' => '2020-01-01', // 過去日
                'mismatches' => [],
                'issues' => [],
            ]))),
        ]);

        $booking = $this->makeBooking(['departure' => now()->addDays(3)->toDateString()]);
        $registration = $this->makeRegistrationForBooking($booking);
        Storage::disk('r2')->put($registration->id_image_path, 'fake-image-data');

        app(GuestIdImageVerifier::class)->verify($registration);

        $fresh = $registration->fresh();
        // AI は ok でも、コード側の post-check で有効期限切れ → suspicious に格上げ
        $this->assertSame('suspicious', $fresh->id_image_verdict);
        $this->assertStringContainsString('有効期限', $fresh->id_image_ai_notes);
    }

    public function test_verify_marks_error_when_image_missing_from_storage(): void
    {
        Http::fake();

        $registration = $this->makeRegistration();
        // storage に画像を put しない → 取得失敗

        app(GuestIdImageVerifier::class)->verify($registration);

        $this->assertSame('error', $registration->fresh()->id_image_verdict);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function claudeReply(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 200],
        ];
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeRegistration(array $attrs = []): GuestRegistration
    {
        $booking = $this->makeBooking();

        return $this->makeRegistrationForBooking($booking, $attrs);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeRegistrationForBooking(Booking $booking, array $attrs = []): GuestRegistration
    {
        return GuestRegistration::create(array_merge([
            'booking_id' => $booking->id,
            'guest_number' => 1,
            'name' => 'Guest',
            'address' => 'Somewhere',
            'age' => 30,
            'nationality' => 'US',
            'sex' => 'male',
            'is_representative' => true,
            'previous_stay' => 'Home',
            'next_destination' => 'Osaka',
            'id_image_path' => 'guest-ids/test.webp',
            'id_image_compressed' => false,
            'submitted_at' => now(),
        ], $attrs));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'beds24_booking_id' => 1000 + random_int(1, 999999),
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'arrival' => now()->addDay()->toDateString(),
            'departure' => now()->addDays(3)->toDateString(),
            'num_adult' => 2,
            'raw_payload' => [],
            'fetched_at' => now(),
        ], $overrides));
    }
}
