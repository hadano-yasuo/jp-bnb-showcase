<?php

namespace Tests\Feature;

use App\Jobs\VerifyGuestIdImageJob;
use App\Models\Booking;
use App\Models\GuestRegistrationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 実 R2 を叩かないよう local fake ディスクへ差し替え
        Storage::fake('r2');
        config(['guest_id_image.disk' => 'r2']);
    }

    public function test_show_returns_form_for_valid_token(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('a', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->get("/register/{$token->token}?lang=ja");

        $response->assertOk();
        $response->assertSee('宿泊者情報登録');
    }

    public function test_show_returns_form_in_english(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('e', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->get("/register/{$token->token}?lang=en");

        $response->assertOk();
        $response->assertSee('Guest Registration');
    }

    public function test_show_returns_404_for_unknown_token(): void
    {
        $response = $this->get('/register/'.str_repeat('z', 48));

        $response->assertNotFound();
    }

    public function test_show_returns_410_for_expired_token(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('b', 48),
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->get("/register/{$token->token}");

        $response->assertStatus(410);
    }

    public function test_store_creates_registration_and_uploads_image(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('c', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $image = UploadedFile::fake()->image('id.jpg', 800, 600);

        $response = $this->post("/register/{$token->token}", $this->validGuestPayload([
            'name' => 'テスト太郎',
            'address' => '東京都千代田区',
            'id_image' => $image,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'submitted');

        $this->assertDatabaseHas('guest_registrations', [
            'booking_id' => $booking->id,
            'guest_number' => 1,
            'name' => 'テスト太郎',
            'nationality' => '日本',
            'sex' => 'male',
        ]);

        $registration = $booking->guestRegistrations()->first();
        $this->assertNotNull($registration->id_image_path);
        Storage::disk('r2')->assertExists($registration->id_image_path);
    }

    public function test_store_rejects_non_japanese_adult_without_id_image(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('f', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $payload = $this->validGuestPayload([
            'nationality_type' => 'other',
            'nationality' => 'USA',
        ]);
        unset($payload['id_image']);

        $response = $this->post("/register/{$token->token}", $payload);

        $response->assertSessionHasErrors('id_image');
        $this->assertDatabaseCount('guest_registrations', 0);
    }

    public function test_store_allows_japanese_minor_without_id_image(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('g', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $payload = $this->validGuestPayload([
            'age' => 10,
            'nationality_type' => 'japan',
        ]);
        unset($payload['id_image']);

        $response = $this->post("/register/{$token->token}", $payload);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('guest_registrations', 1);
    }

    public function test_store_increments_guest_number(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('d', 48),
            'expires_at' => now()->addDays(7),
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->post("/register/{$token->token}", $this->validGuestPayload([
                'name' => "Guest {$i}",
                'id_image' => UploadedFile::fake()->image("id{$i}.jpg", 400, 300),
                // 代表者は 1 人目のみ
                'is_representative' => $i === 1 ? '1' : null,
            ]));
        }

        $this->assertSame([1, 2, 3], $booking->guestRegistrations()->pluck('guest_number')->all());
    }

    public function test_store_saves_sha256_of_uploaded_image(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('h', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $this->post("/register/{$token->token}", $this->validGuestPayload([
            'id_image' => UploadedFile::fake()->image('id.jpg', 400, 300),
        ]));

        $registration = $booking->guestRegistrations()->first();
        $this->assertNotNull($registration->id_image_sha256);
        $this->assertSame(64, strlen($registration->id_image_sha256));
    }

    public function test_store_requires_privacy_consent(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('i', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $payload = $this->validGuestPayload();
        unset($payload['privacy_consent']);

        $response = $this->post("/register/{$token->token}", $payload);

        $response->assertSessionHasErrors('privacy_consent');
        $this->assertDatabaseCount('guest_registrations', 0);
    }

    public function test_store_requires_contact_for_all_guests(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('j', 48),
            'expires_at' => now()->addDays(7),
        ]);

        // 代表者以外でも phone は必須（旅館業法③連絡先の実装）。
        $payload = $this->validGuestPayload(['is_representative' => null]);
        unset($payload['phone']);

        $response = $this->post("/register/{$token->token}", $payload);

        $response->assertSessionHasErrors('phone');
    }

    public function test_second_guest_can_reuse_representative_phone(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('n', 48),
            'expires_at' => now()->addDays(7),
        ]);

        // 1 人目（代表者）を登録
        $this->post("/register/{$token->token}", $this->validGuestPayload([
            'phone' => '09011112222',
        ]));

        // 2 人目: 代表者と同じ phone を明示的に投入して送信できる
        $secondPayload = $this->validGuestPayload([
            'name' => 'Second',
            'is_representative' => null,
            'phone' => '09011112222', // フロント側で auto-fill された値
            'id_image' => UploadedFile::fake()->image('id2.jpg', 400, 300),
        ]);
        unset($secondPayload['arrival_at'], $secondPayload['departure_at']);

        $response = $this->post("/register/{$token->token}", $secondPayload);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('guest_registrations', [
            'booking_id' => $booking->id,
            'guest_number' => 2,
            'phone' => '09011112222',
        ]);
    }

    public function test_store_rejects_second_representative(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('p', 48),
            'expires_at' => now()->addDays(7),
        ]);

        // 1 人目を代表者として登録
        $this->post("/register/{$token->token}", $this->validGuestPayload());
        $this->assertDatabaseCount('guest_registrations', 1);

        // 2 人目も代表者にしようとすると reject
        $payload = $this->validGuestPayload([
            'name' => 'Second',
            'id_image' => UploadedFile::fake()->image('id2.jpg', 400, 300),
        ]);

        $response = $this->post("/register/{$token->token}", $payload);

        $response->assertSessionHasErrors('is_representative');
        $this->assertDatabaseCount('guest_registrations', 1);
    }

    public function test_edit_representative_can_demote_themselves(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('q', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $this->post("/register/{$token->token}", $this->validGuestPayload());
        $original = $booking->guestRegistrations()->first();
        $this->assertTrue($original->is_representative);

        // 編集モードで代表者フラグを外す
        $editPayload = $this->validGuestPayload(['is_representative' => null]);
        unset($editPayload['arrival_at'], $editPayload['departure_at'], $editPayload['id_image']);
        $response = $this->post(
            "/register/{$token->token}?edit={$original->guest_number}",
            $editPayload,
        );

        $response->assertSessionHasNoErrors();
        $this->assertFalse($original->fresh()->is_representative);
    }

    public function test_edit_non_representative_cannot_promote_when_rep_exists(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('r', 48),
            'expires_at' => now()->addDays(7),
        ]);

        // 1 人目を代表者、2 人目を非代表者で登録
        $this->post("/register/{$token->token}", $this->validGuestPayload());
        $secondPayload = $this->validGuestPayload([
            'name' => 'Second',
            'is_representative' => null,
            'id_image' => UploadedFile::fake()->image('id2.jpg', 400, 300),
        ]);
        unset($secondPayload['arrival_at'], $secondPayload['departure_at']);
        $this->post("/register/{$token->token}", $secondPayload);

        $second = $booking->guestRegistrations()->where('guest_number', 2)->first();

        // 編集モードで 2 人目を代表者にしようとしても reject
        $editPayload = $this->validGuestPayload([
            'name' => 'Second Updated',
        ]);
        unset($editPayload['id_image']);
        $response = $this->post(
            "/register/{$token->token}?edit={$second->guest_number}",
            $editPayload,
        );

        $response->assertSessionHasErrors('is_representative');
        $this->assertFalse($second->fresh()->is_representative);
    }

    public function test_validation_errors_use_selected_locale(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('o', 48),
            'expires_at' => now()->addDays(7),
        ]);

        // 名前を空にして英語で送信
        $payload = $this->validGuestPayload(['name' => '']);
        $response = $this->post("/register/{$token->token}?lang=en", $payload);

        $response->assertSessionHasErrors('name');
        $errors = session('errors')->get('name');
        // laravel-lang/lang の英語 validation.php に基づくメッセージ
        $this->assertTrue(
            str_contains($errors[0], 'field is required') || str_contains($errors[0], 'must not be'),
            "Expected English message, got: {$errors[0]}",
        );
    }

    public function test_edit_mode_updates_existing_registration(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('k', 48),
            'expires_at' => now()->addDays(7),
        ]);

        // 初回登録
        $this->post("/register/{$token->token}", $this->validGuestPayload([
            'name' => '初期名',
        ]));
        $this->assertDatabaseCount('guest_registrations', 1);

        // 編集モードで氏名だけ変更
        $original = $booking->guestRegistrations()->first();
        $editPayload = $this->validGuestPayload([
            'name' => '編集後の名前',
        ]);
        unset($editPayload['id_image']); // 画像は変更しない

        $response = $this->post(
            "/register/{$token->token}?edit={$original->guest_number}",
            $editPayload,
        );

        $response->assertRedirect();
        $response->assertSessionHas('status', 'updated');
        $this->assertDatabaseCount('guest_registrations', 1);
        $this->assertSame('編集後の名前', $original->fresh()->name);
    }

    public function test_edit_mode_keeps_existing_image_when_not_reuploaded(): void
    {
        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('l', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $this->post("/register/{$token->token}", $this->validGuestPayload());
        $original = $booking->guestRegistrations()->first();
        $originalPath = $original->id_image_path;

        // 画像なしで編集
        $editPayload = $this->validGuestPayload(['name' => 'updated']);
        unset($editPayload['id_image']);

        $this->post(
            "/register/{$token->token}?edit={$original->guest_number}",
            $editPayload,
        );

        $this->assertSame($originalPath, $original->fresh()->id_image_path);
    }

    public function test_edit_mode_resets_verdict_when_image_replaced(): void
    {
        // VerifyGuestIdImageJob が実 Claude API を叩くのを防ぐため fake。
        Queue::fake();

        $booking = $this->createBooking();
        $token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('m', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $this->post("/register/{$token->token}", $this->validGuestPayload());
        $registration = $booking->guestRegistrations()->first();

        // 事前に AI 判定 + 管理者承認を仕込む
        $registration->update([
            'id_image_verdict' => 'ok',
            'admin_verdict' => 'ok',
            'admin_verdict_reason' => 'reviewed',
            'admin_reviewed_at' => now(),
        ]);

        // 新しい画像で再送信
        $editPayload = $this->validGuestPayload([
            'id_image' => UploadedFile::fake()->image('new.jpg', 400, 300),
        ]);
        $this->post(
            "/register/{$token->token}?edit={$registration->guest_number}",
            $editPayload,
        );

        $fresh = $registration->fresh();
        $this->assertSame('pending', $fresh->id_image_verdict);
        $this->assertNull($fresh->admin_verdict);
        $this->assertNull($fresh->admin_reviewed_at);

        // 再検証 Job も dispatch された
        Queue::assertPushed(VerifyGuestIdImageJob::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validGuestPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'テスト太郎',
            'address' => '東京都千代田区',
            'age' => 30,
            'occupation' => 'Engineer',
            'sex' => 'male',
            'nationality_type' => 'japan',
            'previous_stay' => 'Home',
            'next_destination' => 'Osaka',
            'is_representative' => '1',
            'phone' => '09012345678',
            'arrival_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'departure_at' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'id_image' => UploadedFile::fake()->image('id.jpg', 400, 300),
            'privacy_consent' => '1',
        ], $overrides);
    }

    private function createBooking(): Booking
    {
        return Booking::create([
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
        ]);
    }
}
