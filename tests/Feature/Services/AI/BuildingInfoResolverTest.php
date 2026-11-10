<?php

namespace Tests\Feature\Services\AI;

use App\Models\Building;
use App\Models\Listing;
use App\Models\Message;
use App\Services\AI\BuildingInfoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingInfoResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeMessage(): Message
    {
        return Message::create([
            'beds24_message_id' => 999,
            'beds24_booking_id' => 100,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'source' => 'guest',
            'message' => 'test',
            'is_read' => false,
            'sent_at' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);
    }

    public function test_returns_empty_when_no_listing(): void
    {
        $result = app(BuildingInfoResolver::class)->resolve($this->makeMessage());
        $this->assertSame('', $result);
    }

    public function test_returns_listing_info_without_building(): void
    {
        Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'Building B2階',
            'display_name' => 'Building B2階',
            'floor' => '2F',
            'is_active' => true,
            'info_markdown' => 'wifi: guest-net',
        ]);

        $result = app(BuildingInfoResolver::class)->resolve($this->makeMessage());

        $this->assertStringNotContainsString('Building:', $result);
        $this->assertStringContainsString('Listing: Building B2階 (2F)', $result);
        $this->assertStringContainsString('wifi: guest-net', $result);
    }

    public function test_returns_building_and_listing_info(): void
    {
        $building = Building::create([
            'name' => 'Building B',
            'display_name' => 'Building B',
            'is_active' => true,
            'info_markdown' => "住所: 東京都...\n緊急連絡: 090-...",
        ]);
        Listing::create([
            'building_id' => $building->id,
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'Building B2階',
            'display_name' => 'Building B2階',
            'floor' => '2F',
            'is_active' => true,
            'info_markdown' => "wifi: guest-net\npassword: 12345",
        ]);

        $result = app(BuildingInfoResolver::class)->resolve($this->makeMessage());

        $this->assertStringContainsString('Building: Building B', $result);
        $this->assertStringContainsString('住所: 東京都...', $result);
        $this->assertStringContainsString('Listing: Building B2階 (2F)', $result);
        $this->assertStringContainsString('wifi: guest-net', $result);
    }

    public function test_building_without_info_shows_header_only(): void
    {
        $building = Building::create([
            'name' => 'Building B',
            'is_active' => true,
        ]);
        Listing::create([
            'building_id' => $building->id,
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'x',
            'display_name' => 'Building B2階',
            'is_active' => true,
        ]);

        $result = app(BuildingInfoResolver::class)->resolve($this->makeMessage());

        $this->assertStringContainsString('Building: Building B', $result);
        $this->assertStringContainsString('Listing: Building B2階', $result);
        $this->assertStringNotContainsString('null', $result);
    }
}
