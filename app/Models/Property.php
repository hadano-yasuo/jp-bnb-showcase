<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * beds24 の property。sync の内部データ扱いで admin UI からは非表示。
 * 実世界の建物単位管理は Building で行う。
 *
 * @property int $id
 * @property int $beds24_property_id
 * @property string $name
 * @property string|null $display_name
 * @property bool $is_active
 * @property string|null $notes
 */
#[Fillable(['beds24_property_id', 'name', 'display_name', 'is_active', 'notes'])]
class Property extends Model
{
    protected function casts(): array
    {
        return [
            'beds24_property_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'beds24_property_id', 'beds24_property_id');
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'beds24_property_id', 'beds24_property_id');
    }
}
