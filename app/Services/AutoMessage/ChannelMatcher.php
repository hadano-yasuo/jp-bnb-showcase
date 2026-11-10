<?php

namespace App\Services\AutoMessage;

use App\Models\AutoMessageRule;

/**
 * bookings.api_source と AutoMessageRule.channel_scope の突合。
 *
 * beds24 の api_source は "Airbnb" / "Booking.com" / "Direct" 等の文字列。
 * ルール側は小文字 enum で持つ（airbnb / booking / direct / all）。
 *
 * 自社サイト直接予約は beds24 /bookings POST 時に apiSource='direct' を送っており、
 * 直接予約ルール (CHANNEL_DIRECT) の対象として扱う。
 */
class ChannelMatcher
{
    public function matches(string $channelScope, ?string $apiSource): bool
    {
        if ($channelScope === AutoMessageRule::CHANNEL_ALL) {
            return true;
        }

        $source = strtolower((string) $apiSource);

        return match ($channelScope) {
            AutoMessageRule::CHANNEL_AIRBNB => str_contains($source, 'airbnb'),
            AutoMessageRule::CHANNEL_BOOKING => str_contains($source, 'booking'),
            AutoMessageRule::CHANNEL_DIRECT => $source === '' || $source === 'direct' || $source === 'direct',
            default => false,
        };
    }
}
