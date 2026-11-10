<?php

namespace App\Services\AI;

use App\Models\Listing;
use App\Models\Message;

/**
 * ReplyDrafter が system prompt に注入するための建物・リスティング情報を組み立てる。
 *
 * message.beds24_room_id から Listing を取得 →
 *   listing.building から Building を取得 →
 *   building.info_markdown + listing.info_markdown を合成。
 *
 * どれも nullable。全部 null なら空文字を返す。
 */
class BuildingInfoResolver
{
    public function resolve(Message $message): string
    {
        return $this->resolveByBeds24RoomId($message->beds24_room_id);
    }

    /**
     * beds24_room_id から直接解決 (Message model なしで呼べる、GuestChannel から利用)。
     */
    public function resolveByBeds24RoomId(int $beds24RoomId): string
    {
        $listing = Listing::query()
            ->with('building')
            ->where('beds24_room_id', $beds24RoomId)
            ->first();

        if ($listing === null) {
            return '';
        }

        $sections = [];

        $building = $listing->building;
        if ($building !== null) {
            $sections[] = "Building: {$building->name}";
            if ($building->info_markdown !== null && trim($building->info_markdown) !== '') {
                $sections[] = trim($building->info_markdown);
            }
        }

        $listingHeader = 'Listing: '.($listing->display_name ?? $listing->name);
        if ($listing->floor !== null && $listing->floor !== '') {
            $listingHeader .= " ({$listing->floor})";
        }
        $sections[] = $listingHeader;

        if ($listing->info_markdown !== null && trim($listing->info_markdown) !== '') {
            $sections[] = trim($listing->info_markdown);
        }

        return implode("\n\n", $sections);
    }
}
