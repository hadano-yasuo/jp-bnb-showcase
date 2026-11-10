<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 将来の自動返信精度向上用の学習データ。1操作ごとに1レコード。
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $draft_id
 * @property string $action
 * @property string|null $final_text
 * @property string|null $edit_diff
 * @property Carbon $created_at
 * @property-read Message $message
 * @property-read MessageDraft|null $draft
 */
#[Fillable([
    'message_id',
    'draft_id',
    'action',
    'final_text',
    'edit_diff',
])]
class LearningSignal extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_SENT = 'sent';

    public const ACTION_CLEARED = 'cleared';

    public const ACTION_REGENERATED = 'regenerated';

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<MessageDraft, $this>
     */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(MessageDraft::class);
    }
}
