<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\GuestRegistrationTokens\Pages\ViewGuestRegistrationToken;
use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Models\GuestRegistrationToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Filament の ViewGuestRegistrationToken ページ上での管理者アクション
 * （承認/却下/AI判定に戻す）をカバーするテスト。
 */
class GuestRegistrationTokenAdminActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private GuestRegistrationToken $token;

    private GuestRegistration $registration;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(User::ROLE_ADMIN);

        $booking = Booking::create([
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

        $this->token = GuestRegistrationToken::create([
            'booking_id' => $booking->id,
            'token' => str_repeat('a', 48),
            'expires_at' => now()->addDays(7),
        ]);

        $this->registration = GuestRegistration::create([
            'booking_id' => $booking->id,
            'guest_number' => 1,
            'name' => 'Test Guest',
            'address' => 'Somewhere',
            'age' => 30,
            'nationality' => 'Japan',
            'sex' => 'male',
            'is_representative' => true,
            'previous_stay' => 'Home',
            'next_destination' => 'Osaka',
            'phone' => '090-1234-5678',
            'id_image_path' => 'guest-ids/test.webp',
            'id_image_compressed' => false,
            'id_image_verdict' => 'suspicious',
            'submitted_at' => now(),
        ]);
    }

    public function test_admin_approve_action_sets_verdict_ok(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ViewGuestRegistrationToken::class, ['record' => $this->token->id])
            ->call('approveRegistration', $this->registration->id, 'looks good')
            ->assertHasNoErrors();

        $fresh = $this->registration->fresh();
        $this->assertSame('ok', $fresh->admin_verdict);
        $this->assertSame('looks good', $fresh->admin_verdict_reason);
        $this->assertSame($this->admin->id, $fresh->admin_reviewed_by);
        $this->assertNotNull($fresh->admin_reviewed_at);

        // effective verdict も admin_verdict を優先
        $this->assertSame('ok', $fresh->effectiveVerdict());
    }

    public function test_admin_reject_action_sets_verdict_invalid(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ViewGuestRegistrationToken::class, ['record' => $this->token->id])
            ->call('rejectRegistration', $this->registration->id, 'not a real ID')
            ->assertHasNoErrors();

        $fresh = $this->registration->fresh();
        $this->assertSame('invalid', $fresh->admin_verdict);
        $this->assertSame('not a real ID', $fresh->admin_verdict_reason);
        $this->assertSame('invalid', $fresh->effectiveVerdict());
    }

    public function test_admin_clear_action_reverts_to_ai_verdict(): void
    {
        // まず承認しておく
        $this->registration->update([
            'admin_verdict' => 'ok',
            'admin_verdict_reason' => 'approved',
            'admin_reviewed_at' => now(),
            'admin_reviewed_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewGuestRegistrationToken::class, ['record' => $this->token->id])
            ->call('clearAdminVerdict', $this->registration->id)
            ->assertHasNoErrors();

        $fresh = $this->registration->fresh();
        $this->assertNull($fresh->admin_verdict);
        $this->assertNull($fresh->admin_verdict_reason);
        $this->assertNull($fresh->admin_reviewed_at);
        $this->assertNull($fresh->admin_reviewed_by);

        // effective verdict は AI 判定に戻る
        $this->assertSame('suspicious', $fresh->effectiveVerdict());
    }

    public function test_admin_action_on_unknown_registration_id_is_noop(): void
    {
        // 別 booking のレコードに対する承認は無視される
        $otherBooking = Booking::create([
            'beds24_booking_id' => 2000 + random_int(1, 999999),
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'first_name' => 'Other',
            'last_name' => 'Guest',
            'arrival' => now()->addDay()->toDateString(),
            'departure' => now()->addDays(3)->toDateString(),
            'num_adult' => 1,
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);
        $otherRegistration = GuestRegistration::create([
            'booking_id' => $otherBooking->id,
            'guest_number' => 1,
            'name' => 'Other Guest',
            'address' => 'Elsewhere',
            'age' => 40,
            'nationality' => 'US',
            'sex' => 'female',
            'is_representative' => true,
            'previous_stay' => 'Home',
            'next_destination' => 'Osaka',
            'phone' => '090-9999-9999',
            'id_image_path' => 'guest-ids/other.webp',
            'id_image_compressed' => false,
            'submitted_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewGuestRegistrationToken::class, ['record' => $this->token->id])
            ->call('approveRegistration', $otherRegistration->id)
            ->assertHasNoErrors();

        // 対象外の予約のため状態変化なし
        $this->assertNull($otherRegistration->fresh()->admin_verdict);
    }
}
