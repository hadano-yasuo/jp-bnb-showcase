<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * リスティング（貸出単位 = 部屋）。beds24 では room と呼ばれる。
 * 業界用語に合わせて Listing とする。
 *
 * beds24 sync で自動作成される。admin は building 割当と display_name /
 * info_markdown を編集する。
 *
 * @property int $id
 * @property int|null $building_id
 * @property int $beds24_room_id
 * @property int $beds24_property_id
 * @property string $name
 * @property string|null $display_name
 * @property string|null $floor
 * @property bool $is_active
 * @property string|null $info_markdown
 * @property string|null $airbnb_url
 * @property string|null $wifi_password
 * @property-read Building|null $building
 * @property-read Collection<int, MessageTemplate> $messageTemplates
 * @property-read Collection<int, AutoMessageRule> $autoMessageRules
 */
#[Fillable([
    'building_id',
    'beds24_room_id',
    'beds24_property_id',
    'name',
    'display_name',
    'floor',
    'is_active',
    'info_markdown',
    'airbnb_url',
    'wifi_password',
])]
class Listing extends Model
{
    protected function casts(): array
    {
        return [
            'building_id' => 'integer',
            'beds24_room_id' => 'integer',
            'beds24_property_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'beds24_property_id', 'beds24_property_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'beds24_room_id', 'beds24_room_id');
    }

    /**
     * このリスティング固有の定型文（universal ではないもの）。
     *
     * @return BelongsToMany<MessageTemplate, $this>
     */
    public function messageTemplates(): BelongsToMany
    {
        return $this->belongsToMany(MessageTemplate::class, 'listing_message_template');
    }

    /**
     * @return BelongsToMany<AutoMessageRule, $this>
     */
    public function autoMessageRules(): BelongsToMany
    {
        return $this->belongsToMany(AutoMessageRule::class, 'auto_message_rule_listing');
    }
}
