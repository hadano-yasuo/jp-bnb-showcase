<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * GuestChannel 内の 1 メッセージ。
 *
 * @property int $id
 * @property int $guest_channel_id
 * @property string $source guest / host / system
 * @property string|null $detected_language
 * @property string|null $detected_language_label
 * @property string $message
 * @property string|null $message_ja
 * @property string|null $email_subject
 * @property string|null $emailed_to
 * @property Carbon|null $emailed_at
 * @property bool $no_reply_needed
 * @property Carbon $sent_at
 * @property Carbon|null $read_at
 * @property-read GuestChannel $channel
 */
#[Fillable([
    'guest_channel_id',
    'source',
    'detected_language',
    'detected_language_label',
    'message',
    'message_ja',
    'email_subject',
    'emailed_to',
    'emailed_at',
    'no_reply_needed',
    'sent_at',
    'read_at',
])]
class GuestChannelMessage extends Model
{
    /**
     * timestamptz 列を offset 付きで INSERT し、PG session TZ=UTC での
     * JST wall-clock 誤解釈を防ぐ。詳細は Message::$dateFormat 参照。
     */
    protected $dateFormat = 'Y-m-d H:i:sO';

    public const SOURCE_GUEST = 'guest';

    public const SOURCE_HOST = 'host';

    public const SOURCE_SYSTEM = 'system';

    protected function casts(): array
    {
        return [
            'no_reply_needed' => 'boolean',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    /**
     * PG timestamptz は "+00" で返るので Carbon は UTC のまま。
     * Filament 一覧は setTimezone(JST) を適用するため、ここで JST に寄せて
     * 詳細ページ (直接 format) と表示を揃える。
     */
    protected function asDateTime($value): Carbon
    {
        return parent::asDateTime($value)->setTimezone(config('app.timezone'));
    }

    /**
     * ホストがメール送信 (URL 剥奪 OTA 対策等) を行ったログ system 行。
     * beds24 未投稿 / GuestChannel チャットへの投稿ではなくメール送信のみ、
     * emailed_to に宛先が入る。beds24 Message::isEmailSendLog() と同じ挙動。
     */
    public function isEmailSendLog(): bool
    {
        return $this->source === self::SOURCE_SYSTEM && $this->emailed_to !== null;
    }

    /**
     * @return BelongsTo<GuestChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(GuestChannel::class, 'guest_channel_id');
    }

    public function isFromGuest(): bool
    {
        return $this->source === self::SOURCE_GUEST;
    }

    public function isFromHost(): bool
    {
        return $this->source === self::SOURCE_HOST;
    }

    /**
     * 併記表示に使う日本語訳。ja メッセージや翻訳未完了は null。
     * beds24 側 Message::japaneseTranslation() と同じ挙動。
     */
    public function japaneseTranslation(): ?string
    {
        if ($this->detected_language === 'ja') {
            return null;
        }

        return $this->message_ja;
    }

    /**
     * 言語判定に足るテキストが含まれているか。
     * URL や HTML タグを剥がして 1 文字でも Letter/Number が残れば true。
     */
    /**
     * 表示用: 平文 URL を <a target="_blank"> に変換し、それ以外は HTML エスケープ。
     * Message::autoLinkUrls と同じ挙動を共有する。
     */
    public function renderedMessage(): HtmlString
    {
        return new HtmlString(Message::autoLinkUrls((string) ($this->message ?? '')));
    }

    public function hasDetectableText(): bool
    {
        $s = (string) ($this->message ?? '');
        $s = strip_tags($s);
        $s = (string) preg_replace('#https?://\S+#i', '', $s);

        return preg_match('/[\p{L}\p{N}]/u', $s) === 1;
    }
}
