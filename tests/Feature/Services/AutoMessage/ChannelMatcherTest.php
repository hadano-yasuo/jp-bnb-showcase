<?php

namespace Tests\Feature\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Services\AutoMessage\ChannelMatcher;
use Tests\TestCase;

class ChannelMatcherTest extends TestCase
{
    public function test_airbnb_matches_case_insensitively(): void
    {
        $matcher = new ChannelMatcher;
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_AIRBNB, 'Airbnb'));
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_AIRBNB, 'airbnb'));
        $this->assertFalse($matcher->matches(AutoMessageRule::CHANNEL_AIRBNB, 'Booking.com'));
    }

    public function test_booking_matches_bookingcom_string(): void
    {
        $matcher = new ChannelMatcher;
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_BOOKING, 'Booking.com'));
        $this->assertFalse($matcher->matches(AutoMessageRule::CHANNEL_BOOKING, 'Airbnb'));
    }

    public function test_direct_matches_null_or_direct(): void
    {
        $matcher = new ChannelMatcher;
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_DIRECT, null));
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_DIRECT, 'Direct'));
        $this->assertFalse($matcher->matches(AutoMessageRule::CHANNEL_DIRECT, 'Airbnb'));
    }

    public function test_all_matches_anything(): void
    {
        $matcher = new ChannelMatcher;
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_ALL, 'Airbnb'));
        $this->assertTrue($matcher->matches(AutoMessageRule::CHANNEL_ALL, null));
    }
}
