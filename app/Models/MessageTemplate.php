<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * 返信用の定型文。日本語で本文を保存し、返信フォームから選んで挿入する。
 * 送信時は既存の翻訳フローで自動的にゲスト言語へ翻訳される。
 *
 * リスティング紐付け:
 *  - listings が空 = 全リスティング対象（universal テンプレ）
 *  - listings が特定 = そのリスティングの会話でのみ選択肢に出る
 *
 * @property int $id
 * @property string $name
 * @property string $body
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Listing> $listings
 */
#[Fillable([
    'name',
    'body',
    'sort_order',
    'is_active',
])]
class MessageTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * 適用対象リスティング。空なら全リスティング対象。
     *
     * @return BelongsToMany<Listing, $this>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'listing_message_template');
    }
}
