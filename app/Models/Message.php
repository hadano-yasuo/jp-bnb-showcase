<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * @property int $id
 * @property int|null $beds24_message_id メール送信専用 system 行では null
 * @property int $beds24_booking_id
 * @property string|null $email_subject
 * @property string|null $emailed_to
 * @property Carbon|null $emailed_at
 * @property int $beds24_property_id
 * @property int $beds24_room_id
 * @property int|null $author_owner_id
 * @property string $source
 * @property string|null $detected_language
 * @property string|null $detected_language_label
 * @property string $message
 * @property string|null $message_ja
 * @property bool $is_read
 * @property bool $no_reply_needed
 * @property Carbon $sent_at
 * @property array<string, mixed> $raw_payload
 * @property Carbon $fetched_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Booking|null $booking
 * @property-read Property|null $property
 * @property-read Listing|null $listing
 * @property-read Collection<int, MessageDraft> $drafts
 * @property-read Collection<int, LearningSignal> $learningSignals
 */
#[Fillable([
    'beds24_message_id',
    'beds24_booking_id',
    'beds24_property_id',
    'beds24_room_id',
    'author_owner_id',
    'source',
    'detected_language',
    'detected_language_label',
    'message',
    'message_ja',
    'email_subject',
    'emailed_to',
    'emailed_at',
    'is_read',
    'no_reply_needed',
    'sent_at',
    'raw_payload',
    'fetched_at',
])]
class Message extends Model
{
    public const SOURCE_GUEST = 'guest';

    public const SOURCE_HOST = 'host';

    public const SOURCE_SYSTEM = 'system';

    /**
     * timestamptz 列に INSERT する際に timezone offset まで含めるための書式。
     * これが無いと asDateTime 内 setTimezone(JST) の結果が「JST の壁時計＋TZ 抜き」
     * で PostgreSQL に渡り、session TZ が UTC の場合に UTC として再解釈されて
     * 9 時間ぶんズレる（読み戻し時に更に setTimezone(JST) されて合計 +9 の
     * 二重シフトになる）。'O' サフィックスで JST/UTC どちらでも安全。
     */
    protected $dateFormat = 'Y-m-d H:i:sO';

    protected function casts(): array
    {
        return [
            'beds24_message_id' => 'integer',
            'beds24_booking_id' => 'integer',
            'beds24_property_id' => 'integer',
            'beds24_room_id' => 'integer',
            'author_owner_id' => 'integer',
            'is_read' => 'boolean',
            'no_reply_needed' => 'boolean',
            'sent_at' => 'datetime',
            'emailed_at' => 'datetime',
            'fetched_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * PostgreSQL の timestamptz は "+00" 付きで返るので Carbon が UTC のまま
     * になる。表示で `->format()` を直接呼ぶと UTC 時刻が出てしまうため、
     * ここでモデル内 Carbon をアプリタイムゾーン（Asia/Tokyo）に寄せておく。
     */
    protected function asDateTime($value): Carbon
    {
        return parent::asDateTime($value)->setTimezone(config('app.timezone'));
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'beds24_booking_id', 'beds24_booking_id');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'beds24_property_id', 'beds24_property_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'beds24_room_id', 'beds24_room_id');
    }

    /**
     * @return HasMany<MessageDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(MessageDraft::class);
    }

    /**
     * @return HasMany<LearningSignal, $this>
     */
    public function learningSignals(): HasMany
    {
        return $this->hasMany(LearningSignal::class);
    }

    /**
     * 併記表示に使う日本語訳。
     * ja メッセージや翻訳未完了は null。
     */
    public function japaneseTranslation(): ?string
    {
        if ($this->detected_language === 'ja') {
            return null;
        }

        return $this->message_ja;
    }

    public function isFromGuest(): bool
    {
        return $this->source === self::SOURCE_GUEST;
    }

    /**
     * 言語判定に足るテキストが含まれているか。
     * Airbnb 画像添付は <a href="...jpg">URL</a> のみで実テキストが無く、
     * LanguageDetector に投げると「短すぎ→英語」ルールで en に固定され、
     * 後続の翻訳先言語決定が誤爆する。HTML タグと URL を除去して
     * 1 文字でも意味ある文字（Unicode Letter/Number）が残らなければ判定不能扱い。
     */
    public function hasDetectableText(): bool
    {
        $s = (string) ($this->message ?? '');
        $s = strip_tags($s);
        $s = (string) preg_replace('#https?://\S+#i', '', $s);

        return preg_match('/[\p{L}\p{N}]/u', $s) === 1;
    }

    public function isFromHost(): bool
    {
        return $this->source === self::SOURCE_HOST;
    }

    /**
     * ホストがメール送信 (URL 剥奪 OTA 対策) を行ったログ system 行。
     * beds24 未投稿 (beds24_message_id null)、emailed_to に宛先が入る。
     */
    public function isEmailSendLog(): bool
    {
        return $this->source === self::SOURCE_SYSTEM && $this->emailed_to !== null;
    }

    /**
     * 表示用: 本文中の <a href="..."> を検出し、
     * 画像URL は <img>、その他URLは新規タブ遷移リンクに変換する。
     * また、裸の URL (<a> 無しの平文) も検出して target="_blank" リンクに変換する。
     * それ以外の文字列は HTML エスケープ（CSS の whitespace-pre-wrap で改行保持想定）。
     */
    public function renderedMessage(): HtmlString
    {
        $raw = $this->message ?? '';
        if ($raw === '') {
            return new HtmlString('');
        }

        // <a href="URL">TEXT</a> を分割して交互に処理
        $parts = preg_split(
            '/(<a\s+[^>]*href="[^"]+"[^>]*>.*?<\/a>)/is',
            $raw,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/<a\s+[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', $part, $m)) {
                $url = $m[1];
                $text = trim(strip_tags($m[2])) ?: $url;
                $path = strtolower((string) parse_url($url, PHP_URL_PATH));
                $isImage = (bool) preg_match('/\.(jpe?g|png|gif|webp)$/', $path);

                if ($isImage) {
                    // referrerpolicy="no-referrer": Airbnb/Booking 画像 CDN はホットリンク防止で
                    // Referer 付きリクエストを 403 で拒否する。<a> 側は rel="noopener noreferrer"
                    // でクリック時は素通しできるが、<img> は明示指定しないとデフォルト referrer が付く。
                    $out .= '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'
                        .'<img src="'.e($url).'" alt="attachment" referrerpolicy="no-referrer" class="max-w-full h-auto rounded-lg my-1" style="max-height:300px;" />'
                        .'</a>';
                } else {
                    $out .= '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">'
                        .e($text).'</a>';
                }
            } else {
                $out .= self::autoLinkUrls($part);
            }
        }

        return new HtmlString($out);
    }

    /**
     * 平文中の http(s) URL を <a target="_blank"> に変換する。URL 以外は HTML エスケープ。
     * auto-message の本文に含まれる登録 URL 等をクリック可能にするため。
     */
    public static function autoLinkUrls(string $text): string
    {
        $parts = preg_split('#(https?://[^\s<>"\']+)#', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return e($text);
        }

        $out = '';
        foreach ($parts as $part) {
            if (preg_match('#^https?://#', $part) === 1) {
                $out .= '<a href="'.e($part).'" target="_blank" rel="noopener noreferrer" class="text-blue-600 dark:text-blue-400 underline">'
                    .e($part).'</a>';
            } else {
                $out .= e($part);
            }
        }

        return $out;
    }
}
