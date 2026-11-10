<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI 生成の返信下書き。1件のメッセージに複数の draft が対応（履歴）。
 *
 * @property int $id
 * @property int $message_id
 * @property string|null $prompt
 * @property string|null $previous_draft
 * @property string $language
 * @property string|null $language_label
 * @property string $draft_text
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string $cost_usd
 * @property Carbon $generated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Message $message
 */
#[Fillable([
    'message_id',
    'prompt',
    'previous_draft',
    'language',
    'language_label',
    'draft_text',
    'model',
    'input_tokens',
    'output_tokens',
    'cost_usd',
    'generated_at',
])]
class MessageDraft extends Model
{
    /**
     * @see Message::$dateFormat — 同じ理由で timezone offset を含める。
     */
    protected $dateFormat = 'Y-m-d H:i:sO';

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * PostgreSQL の timestamptz は "+00" 付きで返るため Carbon が UTC のままに
     * なる。Blade で `->format()` を直接呼ぶ画面があるので、モデル内で
     * アプリタイムゾーン（Asia/Tokyo）に寄せておく。
     */
    protected function asDateTime($value): Carbon
    {
        return parent::asDateTime($value)->setTimezone(config('app.timezone'));
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
