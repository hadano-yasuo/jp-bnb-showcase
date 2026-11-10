<?php

namespace App\Filament\Pages\GuestChannels;

use App\Jobs\AI\TranslateGuestChannelMessageJob;
use App\Mail\ContactAckMail;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use App\Models\Listing;
use App\Models\MessageTemplate;
use App\Services\AI\GuestChannelReplyDrafter;
use App\Services\AI\ProofreadService;
use App\Services\AI\TranslationService;
use App\Support\PublicUrl;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * @property-read GuestChannel $channel
 * @property-read ?Listing $listing
 * @property-read Collection<int, MessageTemplate> $templates
 */
class ShowGuestChannel extends Page
{
    protected string $view = 'filament.pages.channels.show';

    protected static ?string $slug = 'channels/{channelId}';

    protected static bool $shouldRegisterNavigation = false;

    public int $channelId;

    /** ホストが入力する日本語本文。翻訳の元にもなる。 */
    public string $body = '';

    /**
     * 送信言語 (ja/en/zh/ko/es/fr/de/pt/th/vi/other)。
     * mount 時に channel->guest_locale で初期化するが、admin がセレクトで変更可能。
     * translatePreview / doSendReply / doGenerate はこの値をターゲット言語として使う。
     */
    public string $language = 'ja';

    /** language === 'other' のときの実言語名 (Portuguese 等)。 */
    public ?string $languageLabel = null;

    /**
     * 「翻訳プレビュー」で生成されるゲスト言語版。ja の会話では空のまま (未使用)。
     */
    public string $translatedBody = '';

    /** 翻訳リクエスト中 (ボタン disabled 制御用)。 */
    public bool $translating = false;

    /**
     * 校正 (proofread) チェックで検出された指摘一覧。空なら OK。
     *
     * @var list<array{type: string, quote: string, reason: string}>
     */
    public array $proofreadIssues = [];

    /** 校正が提案してきた修正後の本文 (空なら無し)。 */
    public string $proofreadSuggestion = '';

    /** AI 下書きに与える追加指示 (空でも可)。 */
    public string $prompt = '';

    /** AI 下書き生成中 (ボタン disabled 制御用)。 */
    public bool $generating = false;

    /** カスタムメール送信モーダル (件名を指定した個別メールを送るためのパネル)。 */
    public bool $emailModalOpen = false;

    public string $emailTo = '';

    public string $emailSubject = '';

    public string $emailBody = '';

    public function mount(int $channelId): void
    {
        $this->channelId = $channelId;
        $channel = GuestChannel::find($channelId);
        abort_unless($channel !== null, 404);

        $this->language = $channel->guest_locale;
    }

    /** 送信言語を変えたら翻訳プレビューを無効化 (再翻訳を促す)。 */
    public function updatedLanguage(): void
    {
        $this->translatedBody = '';
    }

    /**
     * 会話状態: 最新メッセージが guest かつ返信不要フラグなしなら「要返信」。
     * beds24 messages 側 ShowConversation::isUnreplied() と同挙動。
     */
    public function isUnreplied(): bool
    {
        $last = $this->channel->messages->last();

        return $last !== null
            && $last->source === GuestChannelMessage::SOURCE_GUEST
            && ! $last->no_reply_needed;
    }

    /** 最新メッセージが guest かつ返信不要フラグ有りなら「返信不要」。 */
    public function isMarkedNoReplyNeeded(): bool
    {
        $last = $this->channel->messages->last();

        return $last !== null
            && $last->source === GuestChannelMessage::SOURCE_GUEST
            && $last->no_reply_needed;
    }

    /** 最新の guest メッセージに no_reply_needed=true を立てる。 */
    public function markNoReplyNeeded(): void
    {
        $last = $this->channel->messages->last();
        if ($last === null || $last->source !== GuestChannelMessage::SOURCE_GUEST) {
            return;
        }
        $last->update(['no_reply_needed' => true]);
        unset($this->channel);
        Notification::make()->title('返信不要にしました')->success()->send();
    }

    /** no_reply_needed を解除。 */
    public function unmarkNoReplyNeeded(): void
    {
        $last = $this->channel->messages->last();
        if ($last === null || $last->source !== GuestChannelMessage::SOURCE_GUEST) {
            return;
        }
        $last->update(['no_reply_needed' => false]);
        unset($this->channel);
        Notification::make()->title('返信不要を取り消しました')->success()->send();
    }

    public function getTitle(): string
    {
        return 'ゲストチャット #'.$this->channelId;
    }

    #[Computed]
    public function channel(): GuestChannel
    {
        return GuestChannel::with(['messages', 'booking'])->findOrFail($this->channelId);
    }

    /**
     * room_slug から Listing を解決 (config/rooms.php の beds24_room_id 経由)。
     * room_slug なし or 対応 Listing なしは null。
     */
    #[Computed]
    public function listing(): ?Listing
    {
        $slug = $this->channel->room_slug;
        if ($slug === null) {
            return null;
        }

        $beds24RoomId = (int) config('rooms.'.$slug.'.beds24_room_id', 0);
        if ($beds24RoomId === 0) {
            return null;
        }

        return Listing::query()->where('beds24_room_id', $beds24RoomId)->first();
    }

    /**
     * 返信フォームに挿入可能な定型文一覧。
     * - universal (listings 未紐付け)
     * - もしくは現 listing に紐付いたもの
     *
     * @return Collection<int, MessageTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        $listingId = $this->listing?->id;

        /** @var Collection<int, MessageTemplate> $templates */
        $templates = MessageTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) use ($listingId) {
                $q->whereDoesntHave('listings');
                if ($listingId !== null) {
                    $q->orWhereHas('listings', fn ($lq) => $lq->where('listings.id', $listingId));
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $templates;
    }

    /**
     * 定型文を返信本文に挿入 (末尾追記 or 空なら置換)。
     * Blade 変数展開は現状しない (booking コンテキストが無いため)。
     */
    public function useTemplate(int $templateId): void
    {
        $template = MessageTemplate::query()->find($templateId);
        if ($template === null) {
            return;
        }

        $this->body = trim($this->body) === ''
            ? $template->body
            : rtrim($this->body)."\n\n".$template->body;

        $this->translatedBody = '';
    }

    /** body が更新されたら翻訳/校正結果をリセット。 */
    public function updatedBody(): void
    {
        $this->translatedBody = '';
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
    }

    /** 自動生成ボタン。プロンプトなしで下書きを生成。 */
    public function generateAuto(): void
    {
        $this->doGenerate(null);
    }

    /** プロンプトあり生成ボタン。$prompt を必須にして下書き生成。 */
    public function generateWithPrompt(): void
    {
        if (trim($this->prompt) === '') {
            Notification::make()->title('プロンプトが空です')->warning()->send();

            return;
        }
        $this->doGenerate($this->prompt);
    }

    /**
     * AI 下書きを生成して body に挿入。
     * 常に日本語で生成し、ja 以外の会話は生成直後に translatePreview 相当を自動実行して
     * translatedBody にプレビューをセット (手作業削減、ShowConversation と同挙動)。
     */
    protected function doGenerate(?string $userPrompt): void
    {
        $channel = $this->channel;
        $history = $channel->messages()->orderBy('sent_at')->orderBy('id')->get();
        $previousDraft = trim($this->body) !== '' ? $this->body : null;

        $this->generating = true;
        try {
            $draft = app(GuestChannelReplyDrafter::class)->generate(
                channel: $channel,
                history: $history,
                userPrompt: $userPrompt,
                existingDraft: $previousDraft,
            );

            $this->body = $draft;
            $this->translatedBody = '';
            $this->proofreadIssues = [];
            $this->proofreadSuggestion = '';
            $this->prompt = '';

            $translationNote = null;
            if ($this->language !== 'ja') {
                try {
                    $this->translatedBody = app(TranslationService::class)->translateFromJa(
                        japaneseText: $this->body,
                        targetLangCode: $this->language,
                        targetLangLabel: $this->languageLabel,
                    );
                } catch (Throwable $e) {
                    Log::warning('auto translation after guest channel draft generation failed', [
                        'channel_id' => $this->channelId,
                        'error' => $e->getMessage(),
                    ]);
                    $translationNote = '翻訳は失敗したので「翻訳する」を手動で押してください';
                }
            }

            Notification::make()
                ->title('下書きを生成しました')
                ->body($translationNote ?? '')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::warning('guest channel draft generation failed', [
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()->title('生成に失敗しました')->body($e->getMessage())->danger()->send();
        } finally {
            $this->generating = false;
        }
    }

    /**
     * 「翻訳プレビュー」ボタン。body（日本語）→ ゲスト言語に翻訳して translatedBody にセット。
     * ja の会話では翻訳不要なのでコピーのみ。
     */
    public function translatePreview(): void
    {
        if (trim($this->body) === '') {
            Notification::make()->title('本文が空です')->warning()->send();

            return;
        }

        if ($this->language === 'ja') {
            $this->translatedBody = $this->body;
            Notification::make()->title('ゲストは日本語話者のためそのまま送信できます')->success()->send();

            return;
        }

        $this->translating = true;
        try {
            $this->translatedBody = app(TranslationService::class)->translateFromJa(
                japaneseText: $this->body,
                targetLangCode: $this->language,
                targetLangLabel: $this->languageLabel,
            );

            Notification::make()->title('翻訳しました')->body('内容を確認して送信してください')->success()->send();
        } catch (Throwable $e) {
            Log::warning('guest channel reply translation failed', [
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()->title('翻訳に失敗しました')->body($e->getMessage())->danger()->send();
        } finally {
            $this->translating = false;
        }
    }

    /**
     * 送信ボタン。Haiku で日本語本文の誤字脱字チェックを掛け、指摘があればパネル表示、
     * 指摘なしなら直接 doSendReply()。
     */
    public function sendReply(): void
    {
        $this->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $result = app(ProofreadService::class)->check($this->body);
        } catch (Throwable $e) {
            Log::warning('proofread service call failed for guest channel', [
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
            // fail-open: そのまま送信フローへ
            $this->doSendReply();

            return;
        }

        if ($result['ok'] === true) {
            $this->doSendReply();

            return;
        }

        $this->proofreadIssues = $result['issues'];
        $this->proofreadSuggestion = $result['suggestion'];

        Notification::make()
            ->title('誤字脱字の可能性を検出')
            ->body('内容を確認して修正案を採用するか、そのまま送信してください')
            ->warning()
            ->send();
    }

    /** 誤字脱字チェックの警告を無視して送信。 */
    public function sendAnyway(): void
    {
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
        $this->doSendReply();
    }

    /** 誤字脱字チェックの提案文を本文に反映 (再チェックは次の送信で走る)。 */
    public function applyProofreadSuggestion(): void
    {
        if ($this->proofreadSuggestion === '') {
            return;
        }
        $this->body = $this->proofreadSuggestion;
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
        $this->translatedBody = '';
    }

    /** 誤字脱字パネルを閉じる (何もしない、単にリセット)。 */
    public function dismissProofread(): void
    {
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
    }

    /** 本文と翻訳/校正/プロンプトをすべてクリア。 */
    public function clearBody(): void
    {
        $this->body = '';
        $this->translatedBody = '';
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
        $this->prompt = '';
    }

    /** カスタムメール送信モーダルを開く (件名を指定してゲストへ個別メール送信)。 */
    public function openEmailModal(): void
    {
        $this->emailTo = $this->channel->guest_email;
        $this->emailSubject = '';
        $this->emailBody = $this->body;
        $this->emailModalOpen = true;
    }

    public function closeEmailModal(): void
    {
        $this->emailModalOpen = false;
    }

    /** 定型文をメール本文に挿入 (件名は空のまま、本文だけ)。 */
    public function useTemplateForEmail(int $templateId): void
    {
        $template = MessageTemplate::query()->find($templateId);
        if ($template === null) {
            return;
        }

        $this->emailBody = trim($this->emailBody) === ''
            ? $template->body
            : rtrim($this->emailBody)."\n\n".$template->body;
    }

    public function sendCustomEmail(): void
    {
        $this->validate([
            'emailTo' => ['required', 'email', 'max:255'],
            'emailSubject' => ['required', 'string', 'max:255'],
            'emailBody' => ['required', 'string', 'max:10000'],
        ]);

        $channel = $this->channel;
        $to = $this->emailTo;
        $subject = $this->emailSubject;
        $body = $this->emailBody;

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (Throwable $e) {
            Log::warning('guest channel custom email send failed', [
                'channel_id' => $this->channelId,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            Notification::make()->title('メール送信に失敗しました')->body($e->getMessage())->danger()->send();

            return;
        }

        // 履歴に「📧 メール送信」system 行を追加 (beds24 側 messages と同じ形式で保存)。
        // Blade 側 GuestChannelMessage::isEmailSendLog() 分岐が amber カードで詳細表示する。
        GuestChannelMessage::create([
            'guest_channel_id' => $channel->id,
            'source' => GuestChannelMessage::SOURCE_SYSTEM,
            'message' => $body,
            'email_subject' => $subject,
            'emailed_to' => $to,
            'emailed_at' => now(),
            'sent_at' => now(),
        ]);

        Notification::make()->title('メールを送信しました')->success()->send();

        $this->emailModalOpen = false;
        $this->emailSubject = '';
        $this->emailBody = '';
        unset($this->channel);
        $this->dispatch('guest-channel-updated');
    }

    /**
     * 実際にメッセージを保存 + メール送信する本体。
     * ja でない会話は translatedBody が必須。
     */
    protected function doSendReply(): void
    {
        $channel = $this->channel;

        if ($this->language !== 'ja' && trim($this->translatedBody) === '') {
            Notification::make()
                ->title('先に「翻訳プレビュー」を実行してください')
                ->body('日本語入力を確認できるようにゲスト言語で送ります')
                ->warning()
                ->send();

            return;
        }

        $textToSend = $this->language === 'ja' ? $this->body : $this->translatedBody;

        $message = GuestChannelMessage::create([
            'guest_channel_id' => $channel->id,
            'source' => GuestChannelMessage::SOURCE_HOST,
            'message' => trim($textToSend),
            'sent_at' => now(),
        ]);

        TranslateGuestChannelMessageJob::dispatch($message->id);

        // 送信本文は $this->language で翻訳済みなので、メール文面ロケールも
        // channel->guest_locale ではなく送信時に選ばれた言語に合わせる。
        $mailLocale = in_array($this->language, ['ja', 'en', 'es', 'zh', 'ko'], true)
            ? $this->language
            : $channel->guest_locale;

        Mail::to($channel->guest_email)
            ->send(new ContactAckMail(
                guestName: $channel->guest_name ?? '',
                guestMessage: $message->message,
                guestLocale: $mailLocale,
                chatUrl: PublicUrl::route('chat.show', ['token' => $channel->token]),
            ));

        $this->body = '';
        $this->translatedBody = '';
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
        unset($this->channel);

        $this->dispatch('guest-channel-updated');

        Notification::make()
            ->title('返信を送信しました')
            ->body('ゲストにメール通知を送りました。')
            ->success()
            ->send();
    }
}
