<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * beds24 のカレンダー機能で availability=false に設定された日。
 * SyncRoomBlocksJob で `/inventory/rooms/availability` から取得して upsert される。
 *
 * ⚠ availability=false は実予約による埋まりも含まれるため、
 * 「純粋にブロックされている日」を割り出すには Booking で差し引く必要がある。
 * Calendar 表示側で filter する。
 *
 * @property int $id
 * @property int $beds24_room_id
 * @property Carbon $date
 * @property Carbon $fetched_at
 * @property-read Listing|null $listing
 */
#[Fillable(['beds24_room_id', 'date', 'fetched_at'])]
class RoomBlock extends Model
{
    protected function casts(): array
    {
        return [
            'beds24_room_id' => 'integer',
            'date' => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'beds24_room_id', 'beds24_room_id');
    }
}
