<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 実世界の建物単位。admin が UI で管理する。
 *
 * @property int $id
 * @property string $name
 * @property string|null $display_name
 * @property bool $is_active
 * @property string|null $info_markdown
 * @property-read Collection<int, Listing> $listings
 */
#[Fillable(['name', 'display_name', 'is_active', 'info_markdown'])]
class Building extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
